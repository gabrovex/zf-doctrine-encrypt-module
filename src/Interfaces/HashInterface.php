<?php

namespace ZfDoctrineEncryptModule\Interfaces;

/**
 * Interface for hashing
 */
interface HashInterface
{
    /**
     * Must accept string ready for hashing. Returns hash.
     *
     * @param string $data
     *
     * @return string
     */
    public function hash(string $data) : string;

    /**
     * @param string $string
     * @param string $storedString
     *
     * @return bool
     */
    public function verify(string $string, string $storedString) : bool;
}
