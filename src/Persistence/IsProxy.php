<?php

/*
 * This file is part of the zenstruck/foundry package.
 *
 * (c) Kevin Bond <kevinbond@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zenstruck\Foundry\Persistence;

use Symfony\Component\VarExporter\Internal\LazyObjectRegistry;
use Zenstruck\Assert;
use Zenstruck\Foundry\Configuration;
use Zenstruck\Foundry\Exception\PersistenceNotAvailable;
use Zenstruck\Foundry\Object\Hydrator;
use Zenstruck\Foundry\Persistence\Exception\RefreshObjectFailed;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
trait IsProxy
{
    private static array $_autoRefresh = [];
    private object $_object;

    /**
     * @param \Closure():object $_factory
     */
    public function __construct(private \Closure $_factory)
    {
        // the following is basically "unset()" for all properties (including private ones)
        foreach (LazyObjectRegistry::$classResetters[parent::class] ??= LazyObjectRegistry::getClassResetters(parent::class) as $reset) {
            $reset($this, []);
        }

        // now, __get/__set will be called whenever a property is accessed
    }

    public function __get(string $name): mixed
    {
        $this->_autoRefresh();

        $object = $this->_object();

        return self::_property(new \ReflectionClass(parent::class), $name)->getValue($object);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->_autoRefresh();

        $object = $this->_object();

        self::_property(new \ReflectionClass(parent::class), $name)->setValue($object, $value);
    }

    public function _enableAutoRefresh(): static
    {
        $this->_setAutoRefresh(true);

        return $this;
    }

    public function _disableAutoRefresh(): static
    {
        $this->_setAutoRefresh(false);

        return $this;
    }

    public function _withoutAutoRefresh(callable $callback): static
    {
        $original = $this->_getAutoRefresh();
        $this->_setAutoRefresh(false);

        $callback($this);

        $this->_setAutoRefresh($original);

        return $this;
    }

    public function _save(): static
    {
        Configuration::instance()->persistence()->save($this->_object());

        return $this;
    }

    public function _refresh(): static
    {
        $object = $this->_object();

        Configuration::instance()->persistence()->refresh($object);

        $this->_object = $object;

        return $this;
    }

    public function _delete(): static
    {
        Configuration::instance()->persistence()->delete($this->_object());

        return $this;
    }

    public function _get(string $property): mixed
    {
        $this->_autoRefresh();

        return Hydrator::get($this->_object(), $property);
    }

    public function _set(string $property, mixed $value): static
    {
        $this->_autoRefresh();

        Hydrator::set($this->_object(), $property, $value);

        return $this;
    }

    public function _real(): object
    {
        try {
            // we don't want the auto-refresh mechanism to break "real" object retrieval
            $this->_autoRefresh();
        } catch (\Throwable) {
        }

        return $this->_object();
    }

    public function _repository(): ProxyRepositoryDecorator
    {
        return new ProxyRepositoryDecorator(parent::class);
    }

    public function _assertPersisted(string $message = '{entity} is not persisted.'): static
    {
        Assert::that($this->isPersisted())->isTrue($message, ['entity' => parent::class]);

        return $this;
    }

    public function _assertNotPersisted(string $message = '{entity} is persisted but it should not be.'): static
    {
        Assert::that($this->isPersisted())->isFalse($message, ['entity' => parent::class]);

        return $this;
    }

    private function isPersisted(): bool
    {
        try {
            $this->_refresh();

            return true;
        } catch (RefreshObjectFailed $e) {
            if ($e->objectWasDeleted()) {
                return false;
            }

            throw $e;
        }
    }

    private function _autoRefresh(): void
    {
        if (!$this->_getAutoRefresh()) {
            return;
        }

        try {
            // we don't want that "transparent" calls to _refresh() to trigger a PersistenceNotAvailable exception
            // or a RefreshObjectFailed exception when the object was deleted
            $this->_refresh();
        } catch (PersistenceNotAvailable|RefreshObjectFailed $e) {
            if ($e instanceof RefreshObjectFailed && false === $e->objectWasDeleted()) {
                throw $e;
            }
        }
    }

    private function _getAutoRefresh(): bool
    {
        $real = $this->_object();

        static::$_autoRefresh[\spl_object_id($real)] ??= true;

        return static::$_autoRefresh[\spl_object_id($real)];
    }

    private function _setAutoRefresh(bool $autoRefresh): void
    {
        $real = $this->_object();

        static::$_autoRefresh[\spl_object_id($real)] = $autoRefresh;
    }

    // used in ProxyGenerator
    private function unproxyArgs(array $args): array
    {
        return \array_map(unproxy(...), $args);
    }

    private function _object(): object
    {
        return $this->_object ??= ($this->_factory)();
    }

    private static function _property(\ReflectionClass $class, string $name): \ReflectionProperty
    {
        try {
            return $class->getProperty($name);
        } catch (\ReflectionException $e) {
            if (!$class = $class->getParentClass()) {
                throw $e;
            }

            return self::_property($class, $name);
        }
    }
}
