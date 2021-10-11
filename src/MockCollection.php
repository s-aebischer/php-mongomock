<?php

namespace Helmich\MongoMock;

use ArrayIterator;
use Helmich\MongoMock\Log\Index;
use Helmich\MongoMock\Log\Query;
use MongoDB\BSON;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\Regex;
use MongoDB\Collection;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use MongoDB\Model\IndexInfoIteratorIterator;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Operation\FindOneAndUpdate;
use PHPUnit\Framework\Constraint\Constraint;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * A mocked MongoDB collection
 *
 * This class mimicks the behaviour of a MongoDB collection (and also extends
 * the actual `MongoDB\Collection` class and can be used as a drop-in
 * replacement). All operations are performed in-memory and are not persisted.
 *
 * NOTE: This class is not complete! Many methods are missing and I will only
 * implement them as soon as I need them. Feel free to open an issue or (better)
 * a pull request if you need something.
 *
 * @package Helmich\MongoMock
 */
class MockCollection extends Collection
{
    const TYPE_BSON = [
        5 => BSON\Binary::class,
        128 => BSON\Decimal128::class,
        13 => BSON\JavaScript::class,
        127 => BSON\MaxKey::class,
        -1 => BSON\MinKey::class,
        7 => BSON\ObjectId::class,
        11 => BSON\Regex::class,
        17 => BSON\Timestamp::class,
        9 => BSON\UTCDateTime::class
    ];

    const TYPE = [
        1 => 'double',
        2 => 'string',
        3 => 'object',
        4 => 'array',
        8 => 'boolean',
        10 => 'NULL',
        16 => 'integer',
        18 => 'integer'
    ];

    public $queries = [];
    public $documents = [];
    public $indices = [];
    public $dropped = false;

    /** @var string */
    private $name;

    /** @var MockDatabase|null */
    private $db;

    /** @var array */
    private $options = [];

    /** @var array */
    private $typeMap = [
        'array' => BSONArray::class,
        'document' => BSONDocument::class,
        'root' => BSONDocument::class
    ];

    /**
     * @param string $name
     * @param MockDatabase $db
     */
    public function __construct(string $name = 'collection', MockDatabase $db = null, array $options = [])
    {
        $this->name = $name;
        $this->db = $db;
        $this->options = $options;

        if ($db !== null) {
            $this->options = array_merge($db->getOptions(), $options);
        } else {
            $this->options = $options;
        }

        if (isset($this->options['typeMap'])) {
            $this->typeMap = $this->options['typeMap'];
        }
    }

    public function insertOne($document, array $options = [])
    {
        if (!isset($document['_id'])) {
            $document['_id'] = new ObjectID();
        } else {
            // make sure document with the same id does not exist
            foreach ($this->documents as $doc) {
                // if document with the same id already exists
                if ($doc['_id'] == $document['_id']) {
                    throw new BulkWriteException('duplicate key error', 11000);
                }
            }
        }

        if (!$document instanceof BSONDocument) {
            $document = new BSONDocument($document);
        }

        // Possible double instantiation of BSONDocument?
        // With or without the below, it seems that the
        // BSONDocument class recurses automatically.
        // e.g. $document->bsonSerialize() will give the same result
        // so I couldn't write a test to capture the need or not for this.
        // I'm commenting it out anyway.
        // $document = new BSONDocument($document);

        $this->documents[] = $document;

        return new MockInsertOneResult($document['_id']);
    }

    public function insertMany(array $documents, array $options = [])
    {
        $insertedIds = array_map(function ($doc) use ($options) {
            return $this->insertOne($doc, $options)->getInsertedId();
        }, $documents);

        return new MockInsertManyResult($insertedIds);
    }

    public function deleteMany($filter, array $options = [])
    {
        $matcher = $this->matcherFromQuery($filter);
        $count = 0;
        foreach ($this->documents as $i => $doc) {
            if ($matcher($doc)) {
                unset($this->documents[$i]);
                $count++;
            }
        }
        $this->documents = array_values($this->documents);
        return new MockDeleteResult($count, $count);
    }

    public function updateOne($filter, $update, array $options = [])
    {
        $matcher = $this->matcherFromQuery($filter);
        foreach ($this->documents as $i => &$doc) {
            if ($matcher($doc)) {
                $this->updateCore($doc, $update);
                return new MockUpdateResult(1, 1);
            }
        }

        $result = $this->updateUpsert($filter, $update, $options, false);
        $upserted = $result === null ? [] : $result->getInsertedIds();
        return new MockUpdateResult(0, 0, $upserted);
    }

    public function updateMany($filter, $update, array $options = [])
    {
        $matcher = $this->matcherFromQuery($filter);
        $matched = 0;
        foreach ($this->documents as $i => &$doc) {
            if (!$matcher($doc)) {
                continue;
            }

            $this->updateCore($doc, $update);
            $matched++;
        }

        $result = $this->updateUpsert($filter, $update, $options, $matched !== 0);
        $upserted = $result === null ? [] : $result->getInsertedIds();
        return new MockUpdateResult($matched, $matched, $upserted);
    }

    private function updateUpsert($filter, $update, $options, $anyUpdates)
    {
        if (array_key_exists('upsert', $options)) {
            if ($options['upsert'] && !$anyUpdates) {
                if (array_key_exists('$set', $update)) {
                    $documents = [array_merge($filter, $update['$set'])];
                } elseif (array_key_exists('$setOnInsert', $update)) {
                    $documents = [$update['$setOnInsert']];
                } else {
                    $documents = [$update];
                }
                return $this->insertMany($documents, $options);
            }
        }
    }

    private function updateCore(&$doc, $update)
    {
        // The update operators are required, as exemplified here:
        // http://mongodb.github.io/mongo-php-library/tutorial/crud/
        $supported = ['$set', '$unset', '$addToSet', '$inc', '$setOnInsert', '$push'];
        $unsupported = array_diff(array_keys($update), $supported);
        if (count($unsupported) > 0) {
            throw new Exception("Unsupported update operators found: " . implode(', ', $unsupported));
        }

        foreach ($update['$set'] ?? [] as $k => $v) {
            $dot = strpos($k, ".");
            if ($dot !== false) {
                $tmp = &$doc;
                $keys = explode(".", $k);
                if ($keys !== null) {
                    foreach ($keys as $key) {
                        $tmp = &$tmp[$key];
                    }
                    $tmp = $v;
                }
            } else {
                $doc[$k] = $v;
            }
        }

        foreach ($update['$addToSet'] ?? [] as $k => $v) {
            if (array_key_exists($k, $doc)) {
                $doc[$k][] = $v;
            } else {
                $doc[$k] = [$v];
            }
        }

        foreach ($update['$inc'] ?? [] as $k => $v) {
            if (array_key_exists($k, $doc)) {
                $doc[$k] += $v;
            } else {
                $doc[$k] = $v;
            }
        }

        foreach ($update['$unset'] ?? [] as $k => $v) {
            if (isset($doc[$k])) {
                unset($doc[$k]);
            }
        }

        foreach ($update['$inc'] ?? [] as $k => $v) {
            if (isset($doc[$k]) && is_integer($v) && is_integer($doc[$k])) {
                $doc[$k] += $v;
            }
        }

        foreach ($update['$push'] ?? [] as $k => $v) {
            if (isset($doc[$k]) && is_array($doc[$k])) {
                $doc[$k][] = $v;
            }
        }

    }

    public function find($filter = [], array $options = []): MockCursor
    {
        if (isset($options['typeMap'])) {
            $typeMap = array_merge($this->typeMap, $options['typeMap']);
        } else {
            $typeMap = $this->typeMap;
        }

        // record query for future assertions
        $this->queries[] = new Query($filter, $options);

        $matcher = $this->matcherFromQuery($filter);
        $skip = $options['skip'] ?? 0;

        $collectionCopy = array_values($this->documents);

        if (isset($options['sort'])) {
            usort($collectionCopy, function ($a, $b) use ($options): int {
                foreach ($options['sort'] as $key => $dir) {
                    if($key === '$natural') {
                        if($dir === 0) {
                            return -1;
                        }

                        continue;
                    }

                    $av = $a[$key];
                    $bv = $b[$key];

                    if (is_object($av)) {
                        $av = "" . $av;
                    }
                    if (is_object($bv)) {
                        $bv = "" . $bv;
                    }

                    if ($av > $bv) {
                        return $dir;
                    } else {
                        if ($av < $bv) {
                            return -$dir;
                        }
                    }
                }
                return 0;
            });
        }

        if (isset($options['limit'])) {
            $limit = $options['limit'] !== 0 ? $options['limit'] : null;
        }

        $cursor = [];
        foreach ($collectionCopy as $doc) {
            if ($matcher($doc)) {
                if ($skip-- > 0) {
                    continue;
                }
                if (isset($limit)) {
                    if ($limit === 0) {
                        break;
                    }
                    $limit--;
                }

                $cursor[] = $this->typeMap($doc, $typeMap);
            }
        }

        return new MockCursor($cursor);
    }

    public function findOne($filter = [], array $options = [])
    {
        $results = $this->find($filter, $options);
        foreach ($results as $result) {
            return $result;
        }
        return null;
    }

    private function typeMap(BSONDocument $doc, array $typeMap)
    {
        $doc = $this->typeMapArray($doc, $typeMap);

        if ($typeMap['document'] === 'array') {
            $doc = $doc->getArrayCopy();
        } elseif ($typeMap['document'] !== BSONDocument::class) {
            $doc = new $typeMap['document']($doc->getArrayCopy());
        }

        return $doc;
    }

    private function typeMapArray($doc, array $typeMap)
    {
        foreach ($doc as $key => &$value) {
            if (is_array($value) && $typeMap['array'] !== 'array') {
                $value = $this->typeMapArray($value, $typeMap);
                $value = new $typeMap['array']($value);
            }
        }

        return $doc;
    }

    public function count($filter = [], array $options = [])
    {
        $count = 0;
        $matcher = $this->matcherFromQuery($filter);
        foreach ($this->documents as $i => $doc) {
            if ($matcher($doc)) {
                $count++;
            }
        }
        return $count;
    }

    public function countDocuments($filter = [], array $options = [])
    {
        return $this->count($filter, $options);
    }

    public function createIndex($key, array $options = [])
    {
        $name = '';
        if (is_string($key)) {
            $name = $key . '_1';
        } elseif (is_array($key)) {
            foreach ($key as $field => $enabled) {
                if (strlen($name) !== 0) {
                    $name .= '_';
                }

                $name .= $field . '_1';
            }
        }

        $this->indices[$name] = new Index($key, $options);
        return $name;
    }

    public function drop(array $options = [])
    {
        $this->documents = [];
        $this->dropped = true;
    }

    public function aggregate(array $pipeline, array $options = [])
    {
        if (isset($options['typeMap'])) {
            $typeMap = array_merge($this->typeMap, $options['typeMap']);
        } else {
            $typeMap = $this->typeMap;
        }

        $result = array_values($this->documents);
        foreach($pipeline as $operation => $pipe) {
            if(!is_array($pipe)) {
                throw new Exception('aggregation pipe must be an array');
            } elseif(count($pipe) !== 1) {
                throw new Exception('aggregation pipe only supports one operation');
            }

            reset($pipe);
            $operation = key($pipe);
            $pipe = $pipe[$operation];

            switch($operation) {
                case '$match':
                    $result = $this->aggregateMatch($result, $pipe);
                    break;
                case '$lookup':
                    $result = $this->aggregateLookup($result, $pipe);
                    break;
                case '$unwind':
                    $result = $this->aggregateUnwind($result, $pipe);
                    break;
                case '$replaceRoot':
                    $result = $this->aggregateReplaceRoot($result, $pipe);
                    break;
                case '$project':
                    $result = $this->aggregateProject($result, $pipe);
                    break;
                case '$addFields':
                    $result = $this->aggregateAddFields($result, $pipe);
                    break;
                default:
                    throw new Exception('aggregation '.$operation.' is not supported');
            }
        }

        $cursor = [];
        foreach($result as $doc) {
            $cursor[] = $this->typeMap($doc, $typeMap);
        }

        return new MockCursor($cursor);
    }

    protected function aggregateUnwind($documents, $pipe)
    {
        if(!isset($pipe['path'])) {
            throw new Exception('$unwind requires path');
        }

        $field = $pipe['path'];

        if(substr($field, 0, 1) !== '$') {
            throw new Exception('$unwind path requires field with $ prefix');
        }

        $field = substr($field, 1);

        $result = [];
        foreach($documents as $doc) {
            $value = $this->getArrayValue($doc, $field);
            if($value !== null) {
                foreach($value as $sub) {
                    $sub_doc = $doc->getArrayCopy();
                    $sub_doc[$field] = $sub;
                    $result[] = new BSONDocument($sub_doc);
                }
            } else {
                $result[] = $doc;
            }
        }

        return $result;
    }

    protected function aggregateProject($documents, $pipe)
    {
        return $documents;
    }

    protected function aggregateReplaceRoot($documents, $pipe)
    {
        if(!isset($pipe['newRoot'])) {
            throw new Exception('$newRoot requires newRoot');
        }

        $field = $pipe['newRoot'];
        if(substr($field, 0, 1) !== '$') {
            throw new Exception('$newRoot newRoot requires field with $ prefix');
        }

        $field = substr($field, 1);
        foreach($documents as &$doc) {
            $sub = $this->getArrayValue($doc, $field);
            $doc = new BSONDocument($sub);
        }

        return $documents;
    }

    protected function aggregateMatch($documents, $pipe)
    {
        $matcher = $this->matcherFromQuery($pipe);
        $result = [];
        foreach ($documents as $doc) {
            if ($matcher($doc)) {
                $result[] = $doc;
            }
        }

        return $result;
    }

    protected function aggregateLookup($documents, $pipe)
    {
        if(!isset($pipe['from'])) {
            throw new Exception('$lookup requires from');
        }

        if(!isset($pipe['localField'])) {
            throw new Exception('$lookup requires localField');
        }

        if(!isset($pipe['foreignField'])) {
            throw new Exception('$lookup requires foreignField');
        }

        if(!isset($pipe['as'])) {
            throw new Exception('$lookup requires as');
        }

        $remote = $this->db->selectCollection($pipe['from']);

        foreach($documents as &$doc) {
            if(!isset($doc[$pipe['localField']])) {
                $doc[$pipe['as']] = [];
                continue;
            }

            $doc[$pipe['as']] = $remote->find(
                [$pipe['foreignField'] => $doc[$pipe['localField']]]
            )->toArray();
        }


        return $documents;
    }

    protected function aggregateAddFields($documents, $pipe)
    {
        return $documents;
    }

    public function bulkWrite(array $operations, array $options = [])
    {
        // TODO: Implement this function
    }

    public function createIndexes(array $indexes, array $options = [])
    {
        foreach ($indexes as $index) {
            $key = $index['key'];
            unset($index['key']);
            $this->createIndex($key, $index);
        }
    }

    public function deleteOne($filter, array $options = [])
    {
        $matcher = $this->matcherFromQuery($filter);
        foreach ($this->documents as $i => $doc) {
            if ($matcher($doc)) {
                unset($this->documents[$i]);
                $this->documents = array_values($this->documents);
                return;
            }
        }
    }

    public function distinct($fieldName, $filter = [], array $options = [])
    {
        $values = [];

        $matcher = $this->matcherFromQuery($filter);
        foreach ($this->documents as $document){
            if ($matcher($document) && isset($document[$fieldName])) {
                $values[] = $document[$fieldName];
            }
        }
        return array_unique($values);
    }

    public function dropIndex($indexName, array $options = [])
    {
        // TODO: Implement this function
    }

    public function dropIndexes(array $options = [])
    {
        // TODO: Implement this function
    }

    public function findOneAndDelete($filter, array $options = [])
    {
        // TODO: Implement this function
    }

    public function findOneAndReplace($filter, $replacement, array $options = [])
    {
        // TODO: Implement this function
    }

    public function findOneAndUpdate($filter, $update, array $options = [])
    {
        if (!isset($options['returnDocument'])) {
            // Standard behaviour according to https://docs.mongodb.com/php-library/v1.2/reference/method/MongoDBCollection-findOneAndUpdate/#definition
            $options['returnDocument'] = FindOneAndUpdate::RETURN_DOCUMENT_BEFORE;
        }

        $returnDocument = null;
        if ($options['returnDocument'] === FindOneAndUpdate::RETURN_DOCUMENT_BEFORE) {
            $returnDocument = $this->findOne($filter, $options);
        }

        $this->updateOne($filter, $update, $options);

        if ($options['returnDocument'] === FindOneAndUpdate::RETURN_DOCUMENT_AFTER) {
            return $this->findOne($filter, $options);
        } elseif ($returnDocument !== null) {
            return $returnDocument;
        }

        throw new Exception('Given option value "' . $options['returnDocument'] . '" for findOneAndUpdate() "returnDocument" option is invalid');
    }

    public function getCollectionName()
    {
        return $this->name;
    }

    public function getDatabaseName()
    {
        if ($this->db === null) {
            throw new Exception('database required to call getDatabaseName()');
        } else {
            return (string)$this->db;
        }
    }

    public function getNamespace()
    {
        // TODO: Implement this function
    }

    public function listIndexes(array $options = [])
    {
        $indices = [];
        $dbName = $this->db ? $this->db->getDatabaseName() : "unknown";

        foreach ($this->indices as $name => $index) {
            $indices[] = [
                'v'      => 1,
                'unique' => isset($index->getOptions()['unique']) ? $index->getOptions()['unique'] : false,
                'key'    => $index->getKey(),
                'name'   => $name,
                'ns'     => $dbName . '.' . $this->name,
            ];
        }

        return new IndexInfoIteratorIterator(new ArrayIterator($indices));
    }

    public function replaceOne($filter, $replacement, array $options = [])
    {
        // TODO: Implement this function
    }

    public function withOptions(array $options = [])
    {
        // TODO: Implement this function
    }

    public function watch(array $pipeline = [], array $options = [])
    {
        // Set default options
        $fullDocument = 'default';

        foreach ($options as $option => $val) {
            switch ($option) {
                case 'batchSize':
                case 'collation':
                case 'maxAwaitTimeMS':
                case 'readConcern':
                case 'readPreference':
                case 'resumeAfter':
                case 'session':
                case 'startAtOperationTime':
                case 'typeMap':
                    // TODO: Implement these options
                    break;

                case 'fullDocument':
                    if ($val === 'updateLookup') {
                        $fullDocument = $val;
                    }
                    break;
            }
        }

        $result = [
            '_id' => new BSONDocument(['_data' => strtoupper(bin2hex(random_bytes(56)))]),
            'operationType' => 'update',
            'clusterTime' => new BSON\Timestamp(1, time()),
            'ns' => new BSONDocument(['db' => $this->db->getDatabaseName(), 'coll' => $this->getCollectionName()]),
            'documentKey' => new BSONDocument(['_id' => new ObjectId()]),
            'updateDescription' => new BSONDocument(['updatedFields' => new BSONDocument(['foo' => 'bar']), 'removedFields' => new BSONArray([])]),
        ];

        if ($fullDocument === 'updateLookup') {
            if ($pipeline !== []) {
                $typeMap = [
                    'document' => 'array',
                ];

                $result['fullDocument'] = new BSONDocument($this->aggregate($pipeline, ['typeMap' => $typeMap])->toArray()[0]);
            } else {
                $result['fullDocument'] = new BSONDocument($this->documents[0]);
            }
        }

        return new MockCursor([new BSONDocument($result)]);
    }

    private function buildRecursiveMatcherQuery(?array $query): array
    {
        $matchers = [];
        foreach ((array)$query as $field => $value) {
            if ($field === '$and' || $field === '$or' || $field === '$nor' || is_numeric($field)) {
                $matchers[$field] = $this->buildRecursiveMatcherQuery($value);
            } else {
                $matchers[$field] = $this->matcherFromConstraint($value);
            }
        }
        return $matchers;
    }

    private function matcherFromQuery(?array $query): callable
    {
        $matchers = $this->buildRecursiveMatcherQuery($query);
        $orig_matchers = $matchers;
        return $is_match = function ($doc, $compare = null) use (&$is_match, &$matchers, $orig_matchers): bool {
            if ($compare === null) {
                $matchers = $orig_matchers;
            }

            foreach ($matchers as $field => $matcher) {
                if ($field === '$and') {
                    if (!is_array($matcher) || count($matcher) === 0) {
                        throw new Exception('$and expression must be a nonempty array');
                    }

                    foreach ($matcher as $sub) {
                        $matchers = $sub;
                        if (!$is_match($doc, $field)) {
                            return false;
                        }
                    }

                    return true;
                } elseif ($field === '$or') {
                    if (!is_array($matcher) || count($matcher) === 0) {
                        throw new Exception('$or expression must be a nonempty array');
                    }

                    foreach ($matcher as $sub) {
                        $matchers = $sub;
                        if ($is_match($doc, $field)) {
                            return true;
                        }
                    }

                    return false;
                } elseif ($field === '$nor') {
                    if (!is_array($matcher) || count($matcher) === 0) {
                        throw new Exception('$nor expression must be a nonempty array');
                    }

                    foreach ($matcher as $sub) {
                        $matchers = $sub;
                        if ($is_match($doc, $field)) {
                            return false;
                        }
                    }
                    return true;
                } elseif ($field === '$isolated') {
                    return true;
                } else {
                    // needed for case of $exists query filter and field is inexistant
                    $val = $this->getArrayValue($doc, $field);
                    if (!$matcher($val)) {
                        return false;
                    }
                }
            }
            return true;
        };
    }

    private function getArrayValue($array, string $path, string $separator = '.')
    {
        if (isset($array[$path])) {
            return $array[$path];
        }

        $keys = explode($separator, $path);

        foreach ($keys as $key) {
            if (!isset($array[$key])) {
                //needed for case of $exists query filter and field is inexistant
                return null;
            }

            $array = $array[$key];
        }

        return $array;
    }


    private function matcherFromConstraint($constraint): callable
    {
        if (is_callable($constraint)) {
            return $constraint;
        }

        if ($constraint instanceof Constraint) {
            return function ($val) use ($constraint): bool {
                return $constraint->evaluate($val, '', true);
            };
        }

        if ($constraint instanceof ObjectID) {
            return function ($val) use ($constraint): bool {
                return ("" . $constraint) == ("" . $val);
            };
        }

        if ($constraint instanceof Regex) {
            return function ($val) use ($constraint): bool {
                $pattern = str_replace('#', '\\#', $constraint->getPattern());
                return preg_match('#' . $pattern . '#' . $constraint->getFlags(), $val);
            };
        }

        if (is_array($constraint)) {
            return $match = function ($val) use (&$constraint, &$match): bool {
                $result = true;
                foreach ($constraint as $type => $operand) {
                    switch ($type) {
                        // Mongo operators (subset)
                        case '$gt':
                            $result = ($val > $operand);
                            break;
                        case '$gte':
                            $result = ($val >= $operand);
                            break;
                        case '$lt':
                            $result = ($val < $operand);
                            break;
                        case '$lte':
                            $result = ($val <= $operand);
                            break;
                        case '$eq':
                            $result = ($val === $operand);
                            break;
                        case '$ne':
                            $result = ($val != $operand);
                            break;
                        case '$in':
                            $result = (!is_array($val))
                                ? in_array($val, $operand)
                                : array_reduce(
                                    $operand,
                                    function ($acc, $op) use ($val) {
                                        return ($acc || in_array($op, $val));
                                    },
                                    false
                                );
                            break;
                        case '$elemMatch':
                            if (is_array($val)) {
                                $matcher = $this->matcherFromQuery($operand);
                                foreach ($val as $v) {
                                    $result = $matcher($v);
                                    if ($result === true) {
                                        break;
                                    }
                                }
                            } else {
                                $constraint = $operand;
                                $result = $match($val);
                            }
                            break;
                        case '$exists':
                            $result = $val !== null;
                            break;
                        case '$size':
                            $result = count($val) === $operand;
                            break;
                        case '$type':
                            $result = $this->compareType($operand, $val);
                            break;
                        case '$not':
                            if (is_array($operand)) {
                                $matcher = $this->matcherFromConstraint($operand);
                                $result = !$matcher($val);
                            } else {
                                $result = !$operand;
                            }
                            break;
                        // Custom operators
                        case '$instanceOf':
                            $result = is_a($val, $operand);
                            break;

                        default:
                            throw new Exception("Constraint operator '" . $type . "' not yet implemented in MockCollection");
                    }

                    if (!$result) {
                        break;
                    }
                }
                return $result;
            };
        }

        return function ($val) use ($constraint): bool {
            if (is_string($constraint) && $constraint == '$exists') {
                // note that for inexistant fields, val is overridden to be null
                return !is_null($val);
            }

            if ($val instanceof Binary && is_string($constraint)) {
                return $val->getData() == $constraint;
            }

            if (is_array($val) && is_scalar($constraint)) {
                return in_array($constraint, $val, true);
            }

            return $val == $constraint;
        };
    }

    protected function compareType(int $type, $value): bool
    {
        if ($value instanceof BSON\Type) {
            return isset(self::TYPE_BSON[$type]) && is_a($value, self::TYPE_BSON[$type]);
        } else {
            return isset(self::TYPE[$type]) && gettype($value) === self::TYPE[$type];
        }
    }
}
