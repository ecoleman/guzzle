<?php

namespace GuzzleHttp;

use GuzzleHttp\QueryAggregator\QueryAggregatorInterface;
use GuzzleHttp\QueryAggregator\DuplicateAggregator;
use GuzzleHttp\QueryAggregator\PhpAggregator;

/**
 * Manages query string variables and can aggregate them into a string
 */
class Query extends Collection
{
    const RFC3986 = 'RFC3986';
    const RFC1738 = 'RFC1738';

    /** @var bool URL encode fields and values */
    private $encoding = self::RFC3986;

    /** @var QueryAggregatorInterface */
    private $aggregator;

    /**
     * Parse a query string into a Query object
     *
     * @param string $query Query string to parse
     *
     * @return self
     */
    public static function fromString($query)
    {
        $q = new static();
        if ($query === '') {
            return $q;
        }

        $foundDuplicates = $foundPhpStyle = false;

        foreach (explode('&', $query) as $kvp) {
            $parts = explode('=', $kvp, 2);
            $key = rawurldecode($parts[0]);
            if ($paramIsPhpStyleArray = substr($key, -2) == '[]') {
                $foundPhpStyle = true;
                $key = substr($key, 0, -2);
            }
            if (isset($parts[1])) {
                $value = rawurldecode(str_replace('+', '%20', $parts[1]));
                if (isset($q[$key])) {
                    $q->add($key, $value);
                    $foundDuplicates = true;
                } elseif ($paramIsPhpStyleArray) {
                    $q[$key] = array($value);
                } else {
                    $q[$key] = $value;
                }
            } else {
                $q->add($key, null);
            }
        }

        // Use the duplicate aggregator if duplicates were found and not using PHP style arrays
        if ($foundDuplicates && !$foundPhpStyle) {
            $q->setAggregator(new DuplicateAggregator());
        }

        return $q;
    }

    /**
     * Convert the query string parameters to a query string string
     *
     * @return string
     */
    public function __toString()
    {
        if (!$this->data) {
            return '';
        }

        if (!$this->aggregator) {
            $this->aggregator = new PhpAggregator();
        }

        $result = '';
        foreach ($this->aggregator->aggregate($this->data) as $key => $values) {
            foreach ($values as $value) {
                if ($result) {
                    $result .= '&';
                }
                if ($this->encoding == self::RFC1738) {
                    $result .= urlencode($key);
                    if ($value !== null) {
                        $result .= '=' . urlencode($value);
                    }
                } elseif ($this->encoding == self::RFC3986) {
                    $result .= rawurlencode($key);
                    if ($value !== null) {
                        $result .= '=' . rawurlencode($value);
                    }
                } else {
                    $result .= $key;
                    if ($value !== null) {
                        $result .= '=' . $value;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Controls how multi-valued query string parameters are aggregated into a string
     *
     * @param QueryAggregatorInterface $aggregator Converts an array of query string variables into a string
     *
     * @return self
     */
    public function setAggregator(QueryAggregatorInterface $aggregator)
    {
        $this->aggregator = $aggregator;

        return $this;
    }

    /**
     * Specify how values are URL encoded
     *
     * @param string|bool $type One of 'RFC1738', 'RFC3986', or false to disable encoding
     *
     * @return self
     * @throws \InvalidArgumentException
     */
    public function setEncodingType($type)
    {
        if ($type === false || $type === self::RFC1738 || $type === self::RFC3986) {
            $this->encoding = $type;
        } else {
            throw new \InvalidArgumentException('Invalid URL encoding type');
        }

        return $this;
    }
}