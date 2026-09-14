<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos\Tests\Support;

class MemoryRedis
{
    public $values = [];
    public $faults = [];
    public $calls = [];
    public $error;
    public $before;

    public function isConnected()
    {
        return true;
    }
    public function clearLastError()
    {
        $this->error = null;
        return true;
    }
    public function getLastError()
    {
        return $this->error;
    }
    public function setOption($option, $value)
    {
        return $this->invoke('setOption', [$option, $value], static function () {
            return true;
        });
    }
    public function auth($password)
    {
        return $this->invoke('auth', [], static function () {
            return true;
        });
    }
    public function select($db)
    {
        return $this->invoke('select', [$db], static function () {
            return true;
        });
    }
    public function get($key)
    {
        return $this->invoke('get', [$key], function () use ($key) {
            return $this->values[$key] ?? false;
        });
    }
    public function set($key, $value, $options = [])
    {
        return $this->invoke(in_array('NX', $options, true) ? 'nx' : 'set', [$key, $value, $options], function () use ($key, $value, $options) {
            if (in_array('NX', $options, true) && isset($this->values[$key])) {
                return false;
            }
            $this->values[$key] = $value;
            return true;
        });
    }
    public function eval($script, $args, $count)
    {
        return $this->invoke('eval', [$script, $args, $count], function () use ($args, $count) {
            if (($this->values[$args[0]] ?? null) !== $args[$count]) {
                return 0;
            }
            if ($count === 2) {
                $this->values[$args[1]] = $args[3];
            } else {
                unset($this->values[$args[0]]);
            }
            return 1;
        });
    }
    public function del($key)
    {
        return $this->invoke('del', [$key], function () use ($key) {
            $exists = isset($this->values[$key]);
            unset($this->values[$key]);
            return $exists ? 1 : 0;
        });
    }
    private function invoke($operation, $args, $success)
    {
        $this->calls[] = [$operation, $args];
        if ($this->before) {
            ($this->before)($operation, $args, $this);
        }
        if (isset($this->faults[$operation])) {
            $this->error = 'synthetic-password token=synthetic-token';
            if ($this->faults[$operation] === 'throw') {
                throw new \RuntimeException($this->error);
            }
            return false;
        }
        return $success();
    }
}
