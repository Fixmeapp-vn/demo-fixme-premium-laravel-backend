<?php

namespace Tests\Support;

use Illuminate\Cache\ArrayStore;

/**
 * Array cache store that counts metric index writes and atomic increments.
 */
final class CountingArrayStore extends ArrayStore
{
    public int $writes = 0;

    public int $increments = 0;

    public function put($key, $value, $seconds)
    {
        if (str_ends_with($key, ':index')) {
            $this->writes++;
        }

        return parent::put($key, $value, $seconds);
    }

    public function increment($key, $value = 1)
    {
        $this->increments++;

        return parent::increment($key, $value);
    }
}
