<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         4.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\ORM\Behavior\Translate;

use ArrayObject;
use Cake\Core\InstanceConfigTrait;
use Cake\Database\Expression\FieldInterface;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\I18n\I18n;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\PropertyMarshalInterface;
use Cake\ORM\Query;
use Cake\ORM\Table;

/**
 * ShadowTable strategy
 */
class ShadowTableStrategy implements PropertyMarshalInterface
{

    use InstanceConfigTrait;
    use LocatorAwareTrait;

    /**
     * Table instance
     *
     * @var \Cake\ORM\Table
     */
    protected $table;

    /**
     * The locale name that will be used to override fields in the bound table
     * from the translations table
     *
     * @var string
     */
    protected $locale;

    /**
     * Instance of Table responsible for translating
     *
     * @var \Cake\ORM\Table
     */
    protected $translationTable;

    /**
     * Default config
     *
     * These are merged with user-provided configuration when the behavior is used.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'fields' => [],
        'defaultLocale' => null,
        'referenceName' => null,
        'allowEmptyTranslations' => true,
        'onlyTranslated' => false,
        'strategy' => 'subquery',
        'tableLocator' => null,
        'validator' => false,
    ];

    /**
     * Constructor
     *
     * @param \Cake\ORM\Table $table Table instance
     * @param array $config Configuration
     */
    public function __construct(Table $table, array $config = [])
    {
        $tableAlias = $table->getAlias();
        list($plugin) = pluginSplit($table->getRegistryAlias(), true);
        $tableReferenceName = $config['referenceName'];

        $config += [
            'mainTableAlias' => $tableAlias,
            'translationTable' => $plugin . $tableReferenceName . 'Translations',
            'hasOneAlias' => $tableAlias . 'Translation',
        ];

        if (isset($config['tableLocator'])) {
            $this->_tableLocator = $config['tableLocator'];
        }

        $this->setConfig($config);
        $this->table = $table;
        $this->translationTable = $this->getTableLocator()->get($this->_config['translationTable']);

        $this->setupFieldAssociations(
            $this->_config['fields'],
            $this->_config['translationTable'],
            $this->_config['referenceName'],
            $this->_config['strategy']
        );
    }

    /**
     * Return translation table instance.
     *
     * @return \Cake\ORM\Table
     */
    public function getTranslationTable(): Table
    {
        return $this->translationTable;
    }

    /**
     * Sets the locale that should be used for all future find and save operations on
     * the table where this behavior is attached to.
     *
     * When fetching records, the behavior will include the content for the locale set
     * via this method, and likewise when saving data, it will save the data in that
     * locale.
     *
     * Note that in case an entity has a `_locale` property set, that locale will win
     * over the locale set via this method (and over the globally configured one for
     * that matter)!
     *
     * @param string|null $locale The locale to use for fetching and saving records. Pass `null`
     * in order to unset the current locale, and to make the behavior fall back to using the
     * globally configured locale.
     * @return $this
     * @see \Cake\ORM\Behavior\TranslateBehavior::getLocale()
     * @link https://book.cakephp.org/3.0/en/orm/behaviors/translate.html#retrieving-one-language-without-using-i18n-locale
     * @link https://book.cakephp.org/3.0/en/orm/behaviors/translate.html#saving-in-another-language
     */
    public function setLocale(?string $locale)
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Returns the current locale.
     *
     * If no locale has been explicitly set via `setLocale()`, this method will return
     * the currently configured global locale.
     *
     * @return string
     * @see \Cake\I18n\I18n::getLocale()
     * @see \Cake\ORM\Behavior\TranslateBehavior::setLocale()
     */
    public function getLocale(): string
    {
        return $this->locale ?: I18n::getLocale();
    }

    /**
     * Create a hasMany association for all records
     *
     * Don't create a hasOne association here as the join conditions are modified
     * in before find - so create/modify it there
     *
     * @param array $fields - ignored
     * @param string $table - ignored
     * @param string $fieldConditions - ignored
     * @param string $strategy the strategy used in the _i18n association
     *
     * @return void
     */
    public function setupFieldAssociations($fields, $table, $fieldConditions, $strategy)
    {
        $config = $this->getConfig();

        $this->table->hasMany($config['translationTable'], [
            'className' => $config['translationTable'],
            'foreignKey' => 'id',
            'strategy' => $strategy,
            'propertyName' => '_i18n',
            'dependent' => true,
        ]);
    }

    /**
     * Callback method that listens to the `beforeFind` event in the bound
     * table. It modifies the passed query by eager loading the translated fields
     * and adding a formatter to copy the values into the main table records.
     *
     * @param \Cake\Event\Event $event The beforeFind event that was fired.
     * @param \Cake\ORM\Query $query Query
     * @param \ArrayObject $options The options for the query
     * @return void
     */
    public function beforeFind(Event $event, Query $query, ArrayObject $options)
    {
        $locale = $this->getLocale();

        if ($locale === $this->getConfig('defaultLocale')) {
            return;
        }

        $config = $this->getConfig();

        if (isset($options['filterByCurrentLocale'])) {
            $joinType = $options['filterByCurrentLocale'] ? 'INNER' : 'LEFT';
        } else {
            $joinType = $config['onlyTranslated'] ? 'INNER' : 'LEFT';
        }

        $this->table->hasOne($config['hasOneAlias'], [
            'foreignKey' => ['id'],
            'joinType' => $joinType,
            'propertyName' => 'translation',
            'className' => $config['translationTable'],
            'conditions' => [
                $config['hasOneAlias'] . '.locale' => $locale,
            ],
        ]);

        $fieldsAdded = $this->addFieldsToQuery($query, $config);
        $orderByTranslatedField = $this->iterateClause($query, 'order', $config);
        $filteredByTranslatedField = $this->traverseClause($query, 'where', $config);

        if (!$fieldsAdded && !$orderByTranslatedField && !$filteredByTranslatedField) {
            return;
        }

        $query->contain([$config['hasOneAlias']]);

        $query->formatResults(function ($results) use ($locale) {
            return $this->rowMapper($results, $locale);
        }, $query::PREPEND);
    }

    /**
     * Add translation fields to query
     *
     * If the query is using autofields (directly or implicitly) add the
     * main table's fields to the query first.
     *
     * Only add translations for fields that are in the main table, always
     * add the locale field though.
     *
     * @param \Cake\ORM\Query $query the query to check
     * @param array $config the config to use for adding fields
     * @return bool Whether a join to the translation table is required
     */
    protected function addFieldsToQuery(Query $query, array $config)
    {
        if ($query->isAutoFieldsEnabled()) {
            return true;
        }

        $select = array_filter($query->clause('select'), function ($field) {
            return is_string($field);
        });

        if (!$select) {
            return true;
        }

        $alias = $config['mainTableAlias'];
        $joinRequired = false;
        foreach ($this->translatedFields() as $field) {
            if (array_intersect($select, [$field, "$alias.$field"])) {
                $joinRequired = true;
                $query->select($query->aliasField($field, $config['hasOneAlias']));
            }
        }

        if ($joinRequired) {
            $query->select($query->aliasField('locale', $config['hasOneAlias']));
        }

        return $joinRequired;
    }

    /**
     * Iterate over a clause to alias fields
     *
     * The objective here is to transparently prevent ambiguous field errors by
     * prefixing fields with the appropriate table alias. This method currently
     * expects to receive an order clause only.
     *
     * @param \Cake\ORM\Query $query the query to check
     * @param string $name The clause name
     * @param array $config the config to use for adding fields
     * @return bool Whether a join to the translation table is required
     */
    protected function iterateClause(Query $query, $name = '', $config = [])
    {
        $clause = $query->clause($name);
        if (!$clause || !$clause->count()) {
            return false;
        }

        $alias = $config['hasOneAlias'];
        $fields = $this->translatedFields();
        $mainTableAlias = $config['mainTableAlias'];
        $mainTableFields = $this->mainFields();
        $joinRequired = false;

        $clause->iterateParts(function ($c, &$field) use ($fields, $alias, $mainTableAlias, $mainTableFields, &$joinRequired) {
            if (!is_string($field) || strpos($field, '.')) {
                return $c;
            }

            if (in_array($field, $fields)) {
                $joinRequired = true;
                $field = "$alias.$field";
            } elseif (in_array($field, $mainTableFields)) {
                $field = "$mainTableAlias.$field";
            }

            return $c;
        });

        return $joinRequired;
    }

    /**
     * Traverse over a clause to alias fields
     *
     * The objective here is to transparently prevent ambiguous field errors by
     * prefixing fields with the appropriate table alias. This method currently
     * expects to receive a where clause only.
     *
     * @param \Cake\ORM\Query $query the query to check
     * @param string $name The clause name
     * @param array $config the config to use for adding fields
     * @return bool Whether a join to the translation table is required
     */
    protected function traverseClause(Query $query, $name = '', $config = [])
    {
        $clause = $query->clause($name);
        if (!$clause || !$clause->count()) {
            return false;
        }

        $alias = $config['hasOneAlias'];
        $fields = $this->translatedFields();
        $mainTableAlias = $config['mainTableAlias'];
        $mainTableFields = $this->mainFields();
        $joinRequired = false;

        $clause->traverse(function ($expression) use ($fields, $alias, $mainTableAlias, $mainTableFields, &$joinRequired) {
            if (!($expression instanceof FieldInterface)) {
                return;
            }
            $field = $expression->getField();
            if (!is_string($field) || strpos($field, '.')) {
                return;
            }

            if (in_array($field, $fields)) {
                $joinRequired = true;
                $expression->setField("$alias.$field");

                return;
            }

            if (in_array($field, $mainTableFields)) {
                $expression->setField("$mainTableAlias.$field");
            }
        });

        return $joinRequired;
    }

    /**
     * Modifies the entity before it is saved so that translated fields are persisted
     * in the database too.
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @param \ArrayObject $options the options passed to the save method
     * @return void
     */
    public function beforeSave(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        $locale = $entity->get('_locale') ?: $this->getLocale();
        $newOptions = [$this->translationTable->getAlias() => ['validate' => false]];
        $options['associated'] = $newOptions + $options['associated'];

        // Check early if empty translations are present in the entity.
        // If this is the case, unset them to prevent persistence.
        // This only applies if $this->_config['allowEmptyTranslations'] is false
        if ($this->_config['allowEmptyTranslations'] === false) {
            $this->unsetEmptyFields($entity);
        }

        $this->bundleTranslatedFields($entity);
        $bundled = $entity->get('_i18n') ?: [];
        $noBundled = count($bundled) === 0;

        // No additional translation records need to be saved,
        // as the entity is in the default locale.
        if ($noBundled && $locale === $this->getConfig('defaultLocale')) {
            return;
        }

        $values = $entity->extract($this->translatedFields(), true);
        $fields = array_keys($values);
        $noFields = empty($fields);

        // If there are no fields and no bundled translations, or both fields
        // in the default locale and bundled translations we can
        // skip the remaining logic as its not necessary.
        if ($noFields && $noBundled || ($fields && $bundled)) {
            return;
        }

        $primaryKey = (array)$this->table->getPrimaryKey();
        $id = $entity->get(current($primaryKey));

        // When we have no key and bundled translations, we
        // need to mark the entity dirty so the root
        // entity persists.
        if ($noFields && $bundled && !$id) {
            foreach ($this->translatedFields() as $field) {
                $entity->setDirty($field, true);
            }

            return;
        }

        if ($noFields) {
            return;
        }

        $where = compact('id', 'locale');

        $translation = $this->translationTable->find()
            ->select(array_merge(['id', 'locale'], $fields))
            ->where($where)
            ->enableBufferedResults(false)
            ->first();

        if ($translation) {
            foreach ($fields as $field) {
                $translation->set($field, $values[$field]);
            }
        } else {
            $translation = $this->translationTable->newEntity(
                $where + $values,
                [
                    'useSetters' => false,
                    'markNew' => true,
                ]
            );
        }

        $entity->set('_i18n', array_merge($bundled, [$translation]));
        $entity->set('_locale', $locale, ['setter' => false]);
        $entity->setDirty('_locale', false);

        foreach ($fields as $field) {
            $entity->setDirty($field, false);
        }
    }

    /**
     * Unsets the temporary `_i18n` property after the entity has been saved
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired
     * @param \Cake\Datasource\EntityInterface $entity The entity that is going to be saved
     * @return void
     */
    public function afterSave(Event $event, EntityInterface $entity): void
    {
        $entity->unsetProperty('_i18n');
    }

    /**
     * {@inheritDoc}
     */
    public function buildMarshalMap($marshaller, $map, $options)
    {
        $this->translatedFields();

        if (isset($options['translations']) && !$options['translations']) {
            return [];
        }

        return [
            '_translations' => function ($value, $entity) use ($marshaller, $options) {
                /* @var \Cake\Datasource\EntityInterface $entity */
                $translations = $entity->get('_translations');
                foreach ($this->_config['fields'] as $field) {
                    $options['validate'] = $this->_config['validator'];
                    $errors = [];
                    if (!is_array($value)) {
                        return null;
                    }
                    foreach ($value as $language => $fields) {
                        if (!isset($translations[$language])) {
                            $translations[$language] = $this->table->newEntity();
                        }
                        $marshaller->merge($translations[$language], $fields, $options);
                        if ((bool)$translations[$language]->getErrors()) {
                            $errors[$language] = $translations[$language]->getErrors();
                        }
                    }
                    // Set errors into the root entity, so validation errors
                    // match the original form data position.
                    $entity->setErrors($errors);
                }

                return $translations;
            },
        ];
    }

    /**
     * Returns a fully aliased field name for translated fields.
     *
     * If the requested field is configured as a translation field, field with
     * an alias of a corresponding association is returned. Table-aliased
     * field name is returned for all other fields.
     *
     * @param string $field Field name to be aliased.
     * @return string
     */
    public function translationField($field)
    {
        if ($this->getLocale() === $this->getConfig('defaultLocale')) {
            return $this->table->aliasField($field);
        }

        $translatedFields = $this->translatedFields();
        if (in_array($field, $translatedFields)) {
            return $this->getConfig('hasOneAlias') . '.' . $field;
        }

        return $this->table->aliasField($field);
    }

    /**
     * Modifies the results from a table find in order to merge the translated fields
     * into each entity for a given locale.
     *
     * @param \Cake\Datasource\ResultSetInterface $results Results to map.
     * @param string $locale Locale string
     * @return \Cake\Collection\CollectionInterface
     */
    protected function rowMapper($results, $locale)
    {
        $allowEmpty = $this->_config['allowEmptyTranslations'];

        return $results->map(function ($row) use ($allowEmpty) {
            if ($row === null) {
                return $row;
            }

            $hydrated = !is_array($row);

            if (empty($row['translation'])) {
                $row['_locale'] = $this->getLocale();
                unset($row['translation']);

                if ($hydrated) {
                    $row->clean();
                }

                return $row;
            }

            $translation = $row['translation'];

            $keys = $hydrated ? $translation->visibleProperties() : array_keys($translation);

            foreach ($keys as $field) {
                if ($field === 'locale') {
                    $row['_locale'] = $translation[$field];
                    continue;
                }

                if ($translation[$field] !== null) {
                    if ($allowEmpty || $translation[$field] !== '') {
                        $row[$field] = $translation[$field];
                    }
                }
            }

            unset($row['translation']);

            if ($hydrated) {
                $row->clean();
            }

            return $row;
        });
    }

    /**
     * Modifies the results from a table find in order to merge full translation records
     * into each entity under the `_translations` key
     *
     * @param \Cake\Datasource\ResultSetInterface $results Results to modify.
     * @return \Cake\Collection\CollectionInterface
     */
    public function groupTranslations($results)
    {
        return $results->map(function ($row) {
            $translations = (array)$row['_i18n'];
            if (count($translations) === 0 && $row->get('_translations')) {
                return $row;
            }

            $result = [];
            foreach ($translations as $translation) {
                unset($translation['id']);
                $result[$translation['locale']] = $translation;
            }

            $row['_translations'] = $result;
            unset($row['_i18n']);
            if ($row instanceof EntityInterface) {
                $row->clean();
            }

            return $row;
        });
    }

    /**
     * Helper method used to generated multiple translated field entities
     * out of the data found in the `_translations` property in the passed
     * entity. The result will be put into its `_i18n` property
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return void
     */
    protected function bundleTranslatedFields($entity)
    {
        $translations = (array)$entity->get('_translations');

        if (empty($translations) && !$entity->isDirty('_translations')) {
            return;
        }

        $primaryKey = (array)$this->table->getPrimaryKey();
        $key = $entity->get(current($primaryKey));

        foreach ($translations as $lang => $translation) {
            if (!$translation->id) {
                $update = [
                    'id' => $key,
                    'locale' => $lang,
                ];
                $translation->set($update, ['guard' => false]);
            }
        }

        $entity->set('_i18n', $translations);
    }

    /**
     * Unset empty translations to avoid persistence.
     *
     * Should only be called if $this->_config['allowEmptyTranslations'] is false.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check for empty translations fields inside.
     * @return void
     */
    protected function unsetEmptyFields($entity)
    {
        $translations = (array)$entity->get('_translations');
        foreach ($translations as $locale => $translation) {
            $fields = $translation->extract($this->_config['fields'], false);
            foreach ($fields as $field => $value) {
                if (strlen($value) === 0) {
                    $translation->unsetProperty($field);
                }
            }

            $translation = $translation->extract($this->_config['fields']);

            // If now, the current locale property is empty,
            // unset it completely.
            if (empty(array_filter($translation))) {
                unset($entity->get('_translations')[$locale]);
            }
        }

        // If now, the whole _translations property is empty,
        // unset it completely and return
        if (empty($entity->get('_translations'))) {
            $entity->unsetProperty('_translations');
        }
    }

    /**
     * Lazy define and return the main table fields
     *
     * @return array
     */
    protected function mainFields()
    {
        $fields = $this->getConfig('mainTableFields');

        if ($fields) {
            return $fields;
        }

        $fields = $this->table->getSchema()->columns();

        $this->setConfig('mainTableFields', $fields);

        return $fields;
    }

    /**
     * Lazy define and return the translation table fields
     *
     * @return array
     */
    protected function translatedFields()
    {
        $fields = $this->getConfig('fields');

        if ($fields) {
            return $fields;
        }

        $table = $this->translationTable;
        $fields = $table->getSchema()->columns();
        $fields = array_values(array_diff($fields, ['id', 'locale']));

        $this->setConfig('fields', $fields);

        return $fields;
    }
}