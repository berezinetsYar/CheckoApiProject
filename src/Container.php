<?php

declare(strict_types=1);

namespace App;

use ReflectionClass;
use ReflectionNamedType;

/**
 * Минимальный DI-контейнер с autowiring (аналог IServiceProvider в миниатюре).
 *
 * bind(Class, factory?) — регистрация фабрики или singleton-класса;
 * get(Class)        — разрешение зависимости: если есть фабрика — зовём её,
 * иначе рефлексируем конструктор и рекурсивно создаём аргументы по type-hint'ам.
 *
 * В отличие от ASP.NET Core DI здесь нет Scoped-жизненного цикла: весь процесс
 * PHP живёт ровно один HTTP-запрос, поэтому достаточно Singleton.
 */
final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /**
     * @param class-string      $id
     * @param (callable(self): object)|null $factory
     */
    public function bind(string $id, ?callable $factory = null): void
    {
        $this->factories[$id] = $factory ?? static fn (self $c): object => $c->build($id);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            /** @var T */
            return $this->instances[$id];
        }

        $factory = $this->factories[$id]
            ?? static fn (self $c): object => $c->build($id);

        return $this->instances[$id] = $factory($this);
    }

    /**
     * Автосборка объекта по конструктору (по type-hint'ам параметров).
     *
     * @param class-string $id
     */
    private function build(string $id): object
    {
        $reflection = new ReflectionClass($id);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $id();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Cannot auto-wire %s::%s(): no binding for parameter $%s',
                $id,
                '__construct',
                $param->getName()
            ));
        }

        return $reflection->newInstanceArgs($args);
    }
}
