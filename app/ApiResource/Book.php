<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\ApiPlatform\EloquentOptions;
use App\Models\Book as ModelsBook;

#[ApiResource(
    operations: [
        new Get(name: 'books.show', stateOptions: new EloquentOptions(model: ModelsBook::class)),
    ]
)]
class Book
{
    public $id;
    public string $name;
}
