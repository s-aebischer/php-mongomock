<?php

namespace Helmich\MongoMock;

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
class MockSession
{
    /** @var array */
    public $transactionOptions = [];

    public function __construct(){}

    /**
     * Get options.
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->transactionOptions;
    }

    /**
     * Get session.
     *
     * @return array
     */
    public function getSession(): self
    {
        return $this;
    }

    /**
     * Start transaction.
     *
     * @param array $options
     */
    public function startTransaction(array $options){}

    /**
     * Commit transaction.
     */
    public function commitTransaction(){}
}

