<?php

declare(strict_types=1);

namespace App;

use ReflectionClass;
use ReflectionNamedType;

/**
 * Минимальный DI-контейнер (аналог IServiceCollection/IServiceProvider
 * из ASP.NET Core, но без жизненных циклов Scoped — процесс PHP живёт один запрос).
 *
 * Использование:
 *   $container = new Container();
 *   $container->bind(CompanyService::class, fn (Container $c) => new CompanyService($c->get(CheckoApiClient::class)));
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
        unset($this->instances[$id]);
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
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }

        return $this->instances[$id] = $this->autowire($id);
    }

    /**
     * Рекурсивно создаёт объект, разрешая параметры конструктора по type-hint'ам.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function autowire(string $class): object
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException("Class {$class} is not instantiable and has no factory registered.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }

            // Скалярный параметр без фабрики — берём значение по умолчанию.
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(
                "Cannot auto-wire parameter \${$parameter->getName()} of {$class}. Register a factory via bind()."
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
