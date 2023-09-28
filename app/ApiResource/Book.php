<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\ApiPlatform\EloquentOptions;
use App\Models\Book as ModelsBook;

#[ApiResource(
    operations: [
        new Get(name: 'books.show', stateOptions: new EloquentOptions(model: ModelsBook::class)),
        new GetCollection(name: 'books.index', stateOptions: new EloquentOptions(model: ModelsBook::class)),
        new Post(name: 'books.store', stateOptions: new EloquentOptions(model: ModelsBook::class)),
        new Put(name: 'books.update', stateOptions: new EloquentOptions(model: ModelsBook::class)),
        new Delete(name: 'books.destroy', stateOptions: new EloquentOptions(model: ModelsBook::class)),
    ]
)]
class Book
{
    public $id;
    public string $name;
}
