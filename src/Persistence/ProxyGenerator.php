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

use Doctrine\Persistence\Proxy as DoctrineProxy;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 *
 * @internal
 */
final class ProxyGenerator
{
    private function __construct()
    {
    }

    /**
     * @template T of object
     *
     * @param T $object
     *
     * @return T&Proxy<T>
     */
    public static function wrap(object $object): Proxy
    {
        if ($object instanceof Proxy) {
            return $object;
        }

        $class = self::generateClassFor($object);

        return new $class(static fn() => $object);
    }

    /**
     * @template T
     *
     * @param T $what
     *
     * @return T
     */
    public static function unwrap(mixed $what): mixed
    {
        if (\is_array($what)) {
            return \array_map(self::unwrap(...), $what); // @phpstan-ignore-line
        }

        if (\is_string($what) && \is_a($what, Proxy::class, true)) {
            return \get_parent_class($what) ?: throw new \LogicException('Could not unwrap proxy.'); // @phpstan-ignore-line
        }

        if ($what instanceof Proxy) {
            return $what->_real(); // @phpstan-ignore-line
        }

        return $what;
    }

    /**
     * @template T of object
     *
     * @param T $object
     *
     * @return class-string<Proxy<T>&T>
     */
    private static function generateClassFor(object $object): string
    {
        /** @var class-string $class */
        $class = $object instanceof DoctrineProxy ? \get_parent_class($object) : $object::class;
        $proxyClass = self::proxyClassNameFor($class);

        /** @var class-string<Proxy<T>&T> $proxyClass */
        if (\class_exists($proxyClass, autoload: false)) {
            return $proxyClass;
        }

        $proxyCode = <<<CODE
            /**
             * @internal
             */
            final class {class} extends {parent} implements {proxyInterface}
            {
                use \{proxyTrait};
            }
            CODE;

        $proxyCode = \strtr(
            $proxyCode,
            [
                '{class}' => $proxyClass,
                '{parent}' => $class,
                '{proxyInterface}' => Proxy::class,
                '{proxyTrait}' => IsProxy::class,
            ],
        );

        eval($proxyCode); // @phpstan-ignore-line

        return $proxyClass;
    }

    /**
     * @param class-string $class
     */
    public static function proxyClassNameFor(string $class): string
    {
        return \str_replace('\\', '', $class).'Proxy';
    }
}
