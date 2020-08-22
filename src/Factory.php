<?php

namespace Zenstruck\Foundry;

use Doctrine\ORM\Mapping\ClassMetadata;
use Faker;

/**
 * @author Kevin Bond <kevinbond@gmail.com>
 */
class Factory
{
    /** @var Configuration|null */
    private static $configuration;

    /** @var string */
    private $class;

    /** @var callable|null */
    private $instantiator;

    /** @var bool */
    private $persist = true;

    /** @var array<array|callable> */
    private $attributeSet = [];

    /** @var callable[] */
    private $beforeInstantiate = [];

    /** @var callable[] */
    private $afterInstantiate = [];

    /** @var callable[] */
    private $afterPersist = [];

    /**
     * @param array|callable $defaultAttributes
     */
    public function __construct(string $class, $defaultAttributes = [])
    {
        $this->class = $class;
        $this->attributeSet[] = $defaultAttributes;
    }

    /**
     * @param array|callable $attributes
     *
     * @return Proxy|object
     */
    final public function create($attributes = []): Proxy
    {
        if (!$this->persist) {
            return $this->instantiate($attributes);
        }

        return $this->persist($attributes)->save();
    }

    /**
     * @param array|callable $attributes
     *
     * @return Proxy[]|object[]
     */
    final public function createMany(int $number, $attributes = []): array
    {
        return \array_map(
            function() use ($attributes) {
                return $this->create($attributes);
            },
            \array_fill(0, $number, null)
        );
    }

    public function withoutPersisting(): self
    {
        $cloned = clone $this;
        $cloned->persist = false;

        return $cloned;
    }

    /**
     * @param array|callable $attributes
     */
    final public function withAttributes($attributes = []): self
    {
        $cloned = clone $this;
        $cloned->attributeSet[] = $attributes;

        return $cloned;
    }

    /**
     * @param callable $callback (array $attributes): array
     */
    final public function beforeInstantiate(callable $callback): self
    {
        $cloned = clone $this;
        $cloned->beforeInstantiate[] = $callback;

        return $cloned;
    }

    /**
     * @param callable $callback (object $object, array $attributes): void
     */
    final public function afterInstantiate(callable $callback): self
    {
        $cloned = clone $this;
        $cloned->afterInstantiate[] = $callback;

        return $cloned;
    }

    /**
     * @param callable $callback (object|Proxy $object, array $attributes): void
     */
    final public function afterPersist(callable $callback): self
    {
        $cloned = clone $this;
        $cloned->afterPersist[] = $callback;

        return $cloned;
    }

    /**
     * @param callable $instantiator (array $attributes, string $class): object
     */
    final public function instantiateWith(callable $instantiator): self
    {
        $cloned = clone $this;
        $cloned->instantiator = $instantiator;

        return $cloned;
    }

    /**
     * @internal
     */
    final public static function boot(Configuration $configuration): void
    {
        self::$configuration = $configuration;
    }

    /**
     * @internal
     */
    final public static function configuration(): Configuration
    {
        if (!self::isBooted()) {
            throw new \RuntimeException('Foundry is not yet booted. Using in a test: is your Test case using the Factories trait? Using in a fixture: is ZenstruckFoundryBundle enabled for this environment?');
        }

        return self::$configuration;
    }

    /**
     * @internal
     */
    final public static function isBooted(): bool
    {
        return null !== self::$configuration;
    }

    final public static function faker(): Faker\Generator
    {
        return self::configuration()->faker();
    }

    /**
     * @param array|callable $attributes
     */
    private static function normalizeAttributes($attributes): array
    {
        return \is_callable($attributes) ? $attributes(self::faker()) : $attributes;
    }

    /**
     * @param array|callable $attributes
     */
    private function instantiate($attributes): Proxy
    {
        // merge the factory attribute set with the passed attributes
        $attributeSet = \array_merge($this->attributeSet, [$attributes]);

        // normalize each attribute set and collapse
        $attributes = \array_merge(...\array_map([$this, 'normalizeAttributes'], $attributeSet));

        foreach ($this->beforeInstantiate as $callback) {
            $attributes = $callback($attributes);

            if (!\is_array($attributes)) {
                throw new \LogicException('Before Instantiate event callback must return an array.');
            }
        }

//        if (isset($attributes['comments'])) {
//            /** @var self[] $comments */
//            $comments = $attributes['comments'];
//
//            $this->afterInstantiate[] = function($post) use ($comments) {
//                foreach ($comments as $comment) {
//                    $comment->persist(['post' => $post]);
//                }
//            };
//
//            unset($attributes['comments']);
//        }


        $metadata = self::configuration()->objectManagerFor($this->class)->getClassMetadata($this->class);

        if ($metadata instanceof ClassMetadata) {
            foreach ($attributes as $key => $value) { // todo, what if snake/kebab case?
                if (!\is_array($value)) {
                    continue;
                }

                if (!$metadata->hasAssociation($key)) {
                    continue;
                }

                $mapping = $metadata->getAssociationMapping($key);

                if (ClassMetadata::ONE_TO_MANY !== $mapping['type']) {
                    continue;
                }

                $this->afterInstantiate[] = function($object) use ($value) {
                    foreach ($value as $factory) {

                    }
                };

                unset($attributes[$key]);
            }
        }

        // filter each attribute to convert proxies and factories to objects
        $attributes = \array_map(
            function($value) {
                return $this->normalizeAttribute($value);
            },
            $attributes
        );

        // instantiate the object with the users instantiator or if not set, the default instantiator
        $object = ($this->instantiator ?? self::configuration()->instantiator())($attributes, $this->class);

        foreach ($this->afterInstantiate as $callback) {
            $callback($object, $attributes);
        }

        return new Proxy($object);
    }

    /**
     * @param array|callable $attributes
     */
    private function persist($attributes = []): Proxy
    {
        // TODO this now calls afterPersist before the entity is actually saved to the database
        return $this->instantiate($attributes)->persist()->withoutAutoRefresh(function(Proxy $proxy) use ($attributes) {
            foreach ($this->afterPersist as $callback) {
                $proxy->executeCallback($callback, $attributes);
            }
        });
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function normalizeAttribute($value)
    {
        if ($value instanceof Proxy) {
            return $value->object();
        }

        if (\is_array($value)) {
            // possible OneToMany/ManyToMany relationship
            return \array_map(
                function($value) {
                    return $this->normalizeAttribute($value);
                },
                $value
            );
        }

        if (!$value instanceof self) {
            return $value;
        }

        if (!$this->persist) {
            // ensure attribute Factory's are also not persisted
            return $value->withoutPersisting()->create()->object();
        }

        return $value->persist()->object();
    }
}
