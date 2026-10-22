<?php

declare(strict_types=1);

namespace App;

use ReflectionNamedType;

/**
 * Минимальный DI-контейнер (аналог IServiceCollection/IServiceProvider
 * из ASP.NET Core, но без жизненных циклов и AOT).
 *
 * Использование:
 *   $container = new Container();
 *   $container->bind(CompanyService::class, fn () => new CompanyService(new ApiClient()));
 *   $controller = $container->get(CompanyController::class); // зависимости — по type-hint'ам
 */
final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, object> одиночные экземпляры (singleton) */
    private array $instances = [];

    /**
     * Регистрирует фабрику для класса/интерфейса.
     *
     * @param class-string|string $id
     */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /**
     * Возвращает экземпляр: сначала — из registered factory,
     * иначе — автозаполнение конструктора по type-hint'ам (рефлексия).
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id]; // php@8.2
        }

        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }

        return $this->instances[$id] = $this->autowire($id);
    }

    /**
     * Рекурсивно создаёт объект, разрешая параметры конструктора.
     * Скалярные/приводимые к скалярам параметры используют значения по умолчанию.
     */
    private function autowire(string $class): object
    {
        $reflector = new \ReflectionClass($class);
        $ctor      = $reflector->getConstructor();

        if ($ctor === null) {
            return new $class();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(
                "Cannot resolve parameter \${$param->getName()} of {$class}"
            );
        }

        return $reflector->newInstanceArgs($args);
    }
}
