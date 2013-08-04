<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;

/**
 * SplObjectStorage object that only allows Requests that map to Responses or Exception
 */
class Transaction extends \SplObjectStorage
{
    /** @var ClientInterface */
    private $client;

    /**
     * @param ClientInterface $client Client that is used to send the requests
     */
    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    public function offsetSet($object, $data = null)
    {
        if (!($object instanceof RequestInterface)) {
            throw new \InvalidArgumentException('Offset must be a request');
        }

        if (!($data instanceof ResponseInterface || $data instanceof RequestException)) {
            throw new \InvalidArgumentException('Value must be a response or RequestException');
        }

        parent::offsetSet($object, $data);
    }

    /**
     * Get an array of results of the transaction. Each item in the array is
     * either a {@see ResponseInterface} or {@see RequestException} object.
     *
     * @return array
     */
    public function getResults()
    {
        $responses = [];
        foreach ($this as $request) {
            $responses[] = $this[$request];
        }

        return $responses;
    }

    /**
     * Get a Transaction object that only contains valid responses
     *
     * @return \SplObjectStorage Map of responses to requests
     */
    public function getResponses()
    {
        $hash = new \SplObjectStorage();
        foreach ($this as $request) {
            if ($this[$request] instanceof ResponseInterface) {
                $hash[$this[$request]] = $request;
            }
        }

        return $hash;
    }

    /**
     * Check if the transaction has any exceptions
     *
     * @return bool
     */
    public function hasExceptions()
    {
        foreach ($this as $request) {
            if ($this[$request] instanceof \Exception) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a Transaction object that only contains exceptions
     *
     * @return \SplObjectStorage Map of exceptions to requests
     */
    public function getExceptions()
    {
        $transaction = new \SplObjectStorage();
        foreach ($this as $request) {
            if ($this[$request] instanceof \Exception) {
                $transaction[$this[$request]] = $request;
            }
        }

        return $transaction;
    }

    /**
     * Get the client that sent the requests
     *
     * @return ClientInterface
     */
    public function getClient()
    {
        return $this->client;
    }
}