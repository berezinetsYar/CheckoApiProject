<?php

declare(strict_types=1);

namespace Tests\Unit\Dto;

use App\Dto\CompanyDto;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompanyDtoTest extends TestCase
{
    #[Test]
    public function fromArrayCreatesDtoWithAllFields(): void
    {
        $dto = CompanyDto::fromArray([
            'source'   => 'checko',
            'inn'      => '8601023568',
            'name'     => 'АО "ГСК "ЮГОРИЯ""',
            'ogrn'     => '1048600005728',
            'address'  => 'г. Ханты-Мансийск, ул. Комсомольская, зд. 61',
            'status'   => 'Действует',
            'director' => 'Языков Андрей Дмитриевич',
            'raw'      => ['meta' => ['count' => 1]],
        ]);

        $this->assertSame('checko', $dto->source);
        $this->assertSame('8601023568', $dto->inn);
        $this->assertSame('АО "ГСК "ЮГОРИЯ""', $dto->name);
        $this->assertSame('1048600005728', $dto->ogrn);
        $this->assertSame('г. Ханты-Мансийск, ул. Комсомольская, зд. 61', $dto->address);
        $this->assertSame('Действует', $dto->status);
        $this->assertSame('Языков Андрей Дмитриевич', $dto->director);
        $this->assertSame(['meta' => ['count' => 1]], $dto->raw);
    }

    #[Test]
    public function fromArrayAppliesDefaultsForMissingFields(): void
    {
        $dto = CompanyDto::fromArray([]);

        $this->assertSame('checko', $dto->source, 'source по умолчанию — checko');
        $this->assertSame('', $dto->inn);
        $this->assertSame('', $dto->name);
        $this->assertNull($dto->ogrn);
        $this->assertNull($dto->address);
        $this->assertNull($dto->status);
        $this->assertNull($dto->director);
        $this->assertSame([], $dto->raw);
    }

    #[Test]
    public function fromArrayCastsScalarValuesToString(): void
    {
        // Например, ОГРН мог прийти числом из JSON — DTO нормализует его в string.
        $dto = CompanyDto::fromArray([
            'source' => 'mock',
            'inn'    => 7707083893,
            'name'   => 'Тест',
            'ogrn'   => 1027700132195,
        ]);

        $this->assertSame('7707083893', $dto->inn);
        $this->assertSame('1027700132195', $dto->ogrn);
    }

    #[Test]
    public function toArrayReturnsPublicContractWithoutRawPayload(): void
    {
        $dto = CompanyDto::fromArray([
            'source'   => 'checko',
            'inn'      => '8601023568',
            'name'     => 'АО "ГСК "ЮГОРИЯ""',
            'ogrn'     => '1048600005728',
            'address'  => 'г. Ханты-Мансийск',
            'status'   => 'Действует',
            'director' => 'Языков А. Д.',
            'raw'      => ['huge' => 'payload'],
        ]);

        $expected = [
            'source'   => 'checko',
            'inn'      => '8601023568',
            'name'     => 'АО "ГСК "ЮГОРИЯ""',
            'ogrn'     => '1048600005728',
            'address'  => 'г. Ханты-Мансийск',
            'status'   => 'Действует',
            'director' => 'Языков А. Д.',
        ];

        $this->assertSame($expected, $dto->toArray());
        $this->assertArrayNotHasKey('raw', $dto->toArray());
    }

    #[Test]
    public function toArrayKeepsNullsForOptionalFields(): void
    {
        $dto = CompanyDto::fromArray(['inn' => '1234567890', 'name' => 'Минимальная']);

        $array = $dto->toArray();

        $this->assertArrayHasKey('ogrn', $array);
        $this->assertNull($array['ogrn']);
        $this->assertNull($array['address']);
        $this->assertNull($array['status']);
        $this->assertNull($array['director']);
    }

    #[Test]
    public function roundTripFromArrayToArrayIsStable(): void
    {
        $original = [
            'source'   => 'mock',
            'inn'      => '7707083893',
            'name'     => 'ПАО «Заглушка»',
            'ogrn'     => '1027700132195',
            'address'  => 'г. Москва, ул. Тестовая, д. 1',
            'status'   => 'ACTIVE',
            'director' => 'Сидоров С. С.',
        ];

        $dto = CompanyDto::fromArray($original + ['raw' => ['mock' => true]]);

        $this->assertSame($original, $dto->toArray());
    }

    #[Test]
    public function dtoIsReadonlyValueObject(): void
    {
        $dto = CompanyDto::fromArray(['inn' => '1234567890']);

        $reflection = new \ReflectionClass($dto);

        $this->assertTrue($reflection->isFinal(), 'DTO должен быть final (аналог C# record)');
        $this->assertTrue($reflection->isReadOnly(), 'DTO должен быть readonly');
    }
}
