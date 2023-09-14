<?php

namespace App\Http\Controllers;

use ApiPlatform\Exception\InvalidIdentifierException;
use ApiPlatform\Exception\InvalidUriVariableException;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Operation\Factory\OperationMetadataFactory;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\UriVariablesResolverTrait;
use ApiPlatform\Util\OperationRequestInitiatorTrait;
use App\Models\Book;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Database\ConnectionInterface;

class ApiController extends Controller
{
    use UriVariablesResolverTrait;

    public function __construct(
        protected OperationMetadataFactory $operationMetadataFactory,
        protected ProviderInterface $provider,
        protected ProcessorInterface $processor,
        protected Application $app,
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        /** @var Route */
        $route = $request->getRouteResolver()();
        $parameters = $route->parameters;
        $keys = array_keys($parameters);
        /** @var HttpOperation */
        $operation = $this->operationMetadataFactory->create($route->getName());
        $operation = $operation
            // TODO: move this to a metadata factory when the model is an eloquent model
            ->withProvider(
                fn(Operation $operation, array $uriVariables = [])
                => $this->app->make($operation->getStateOptions()->model)::all()
            );

        $uriVariables = [];
        try {
            $uriVariables = $this->getOperationUriVariables($operation, $parameters, $operation->getClass());
        } catch (InvalidIdentifierException|InvalidUriVariableException $e) {
            throw new NotFoundHttpException('Invalid uri variables.', $e);
        }

        $context = [
            'request' => &$request,
            'uri_variables' => $uriVariables,
            'resource_class' => $operation->getClass(),
        ];

        if (null === $operation->canValidate()) {
            $operation = $operation->withValidate(!$request->isMethodSafe() && !$request->isMethod('DELETE'));
        }

        $body = $this->provider->provide($operation, $uriVariables, $context);
        if (null === $body) {
            throw new NotFoundHttpException();
        }
        $context['previous_data'] = $request->attributes->get('previous_data');
        $context['data'] = $request->attributes->get('data');

        if (null === $operation->canWrite()) {
            $operation = $operation->withWrite(!$request->isMethodSafe());
        }

        return $this->processor->process($body, $operation, $uriVariables, $context);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        /** @var Route $route */
        $route = $request->getRouteResolver()();
        $parameters = $route->parameters;
        $keys = array_keys($parameters);
        /** @var HttpOperation $operation */
        $operation = $this->operationMetadataFactory->create($route->getName());
        $operation = $operation
            ->withProcessor(
                function ($data, Operation $operation, array $uriVariables = [], array $context = []) {
                    $data = $this->app->make($operation->getStateOptions()->model)
                        ->fill($context['request']->all())
                    ;
                    $data->saveOrFail();
                    $data->refresh();

                    return $data;
                }
            );

        try {
            $uriVariables = $this->getOperationUriVariables($operation, $parameters, $operation->getClass());
        } catch (InvalidIdentifierException|InvalidUriVariableException $e) {
            throw new NotFoundHttpException('Invalid uri variables.', $e);
        }

        $context = [
            'request' => &$request,
            'uri_variables' => $uriVariables,
            'resource_class' => $operation->getClass(),
        ];

        if (null === $operation->canValidate()) {
            $operation = $operation->withValidate(!$request->isMethodSafe() && !$request->isMethod('DELETE'));
        }

        $body = null;
        $context['previous_data'] = $request->attributes->get('previous_data');
        $context['data'] = $request->attributes->get('data');

        if (null === $operation->canWrite()) {
            $operation = $operation->withWrite(!$request->isMethodSafe());
        }

        return $this->processor->process($body, $operation, $uriVariables, $context);
    }

    /**
     * Provision a new web server.
     */
    public function show(Request $request)
    {
        /** @var Route */
        $route = $request->getRouteResolver()();
        $parameters = $route->parameters;
        $keys = array_keys($parameters);
        /** @var HttpOperation */
        $operation = $this->operationMetadataFactory->create($route->getName());
        $operation = $operation
            ->withUriVariables([$keys[0] => new Link(identifiers: ['id'])])
            // TODO: move this to a metadata factory when the model is an eloquent model
            ->withProvider(
               fn(Operation $operation, array $uriVariables = [])
                => $this->app->make($operation->getStateOptions()->model)
                             ->resolveRouteBinding($uriVariables)
            );

        // $operation = new Get(
        //     class: Book::class,
        //     name: $route->getName(),
        //     uriVariables: ,
        //     provider: ;

        $uriVariables = [];
        try {
            $uriVariables = $this->getOperationUriVariables($operation, $parameters, $operation->getClass());
        } catch (InvalidIdentifierException|InvalidUriVariableException $e) {
            throw new NotFoundHttpException('Invalid uri variables.', $e);
        }

        $context = [
            'request' => &$request,
            'uri_variables' => $uriVariables,
            'resource_class' => $operation->getClass(),
        ];

        if (null === $operation->canValidate()) {
            $operation = $operation->withValidate(!$request->isMethodSafe() && !$request->isMethod('DELETE'));
        }

        $body = $this->provider->provide($operation, $uriVariables, $context);
        if (null === $body) {
            throw new NotFoundHttpException();
        }
        $context['previous_data'] = $request->attributes->get('previous_data');
        $context['data'] = $request->attributes->get('data');

        if (null === $operation->canWrite()) {
            $operation = $operation->withWrite(!$request->isMethodSafe());
        }

        return $this->processor->process($body, $operation, $uriVariables, $context);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        /** @var Route $route */
        $route = $request->getRouteResolver()();
        $parameters = $route->parameters;
        $keys = array_keys($parameters);
        /** @var HttpOperation $operation */
        $operation = $this->operationMetadataFactory->create($route->getName());
        $operation = $operation
            ->withUriVariables([$keys[0] => new Link(identifiers: ['id'])])
            // TODO: move this to a metadata factory when the model is an eloquent model
            ->withProvider(
                fn(Operation $operation, array $uriVariables = [])
                => $this->app->make($operation->getStateOptions()->model)
                    ->resolveRouteBinding($uriVariables)
            )
            ->withProcessor(
                function ($data, Operation $operation, array $uriVariables = [], array $context = []) {
                    $data->updateOrFail($context['request']->all());
                    $data->refresh();

                    return $data;
                }
            );

        try {
            $uriVariables = $this->getOperationUriVariables($operation, $parameters, $operation->getClass());
        } catch (InvalidIdentifierException|InvalidUriVariableException $e) {
            throw new NotFoundHttpException('Invalid uri variables.', $e);
        }

        $context = [
            'request' => &$request,
            'uri_variables' => $uriVariables,
            'resource_class' => $operation->getClass(),
        ];

        if (null === $operation->canValidate()) {
            $operation = $operation->withValidate(!$request->isMethodSafe() && !$request->isMethod('DELETE'));
        }

        $body = $this->provider->provide($operation, $uriVariables, $context);
        if (null === $body) {
            throw new NotFoundHttpException();
        }
        $context['previous_data'] = $request->attributes->get('previous_data');
        $context['data'] = $request->attributes->get('data');

        if (null === $operation->canWrite()) {
            $operation = $operation->withWrite(!$request->isMethodSafe());
        }

        return $this->processor->process($body, $operation, $uriVariables, $context);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request)
    {
        /** @var Route */
        $route = $request->getRouteResolver()();
        $parameters = $route->parameters;
        $keys = array_keys($parameters);
        /** @var HttpOperation */
        $operation = $this->operationMetadataFactory->create($route->getName());
        $operation = $operation->withUriVariables([$keys[0] => new Link(identifiers: ['id'])])
            ->withProvider(
                fn(Operation $operation, array $uriVariables = [])
                => $this->app->make($operation->getStateOptions()->model)
                    ->resolveRouteBinding($uriVariables)
            )
            ->withProcessor(
                function ($data, Operation $operation) {
                    $data->delete();
                }
            );

        $uriVariables = [];
        try {
            $uriVariables = $this->getOperationUriVariables($operation, $parameters, $operation->getClass());
        } catch (InvalidIdentifierException|InvalidUriVariableException $e) {
            throw new NotFoundHttpException('Invalid uri variables.', $e);
        }

        $context = [
            'request' => &$request,
            'uri_variables' => $uriVariables,
            'resource_class' => $operation->getClass(),
        ];

        if (null === $operation->canValidate()) {
            $operation = $operation->withValidate(!$request->isMethodSafe() && !$request->isMethod('DELETE'));
        }

        $body = $this->provider->provide($operation, $uriVariables, $context);

        $context['previous_data'] = $request->attributes->get('previous_data');
        $context['data'] = $request->attributes->get('data');

        if (null === $operation->canWrite()) {
            $operation = $operation->withWrite(!$request->isMethodSafe());
        }

        return $this->processor->process($body, $operation, $uriVariables, $context);
    }
}

// use ApiPlatform\Api\UriVariablesConverterInterface;
// use ApiPlatform\Exception\InvalidIdentifierException;
// use ApiPlatform\Exception\InvalidUriVariableException;
// use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
// use ApiPlatform\State\ProcessorInterface;
// use ApiPlatform\State\ProviderInterface;
// use ApiPlatform\State\UriVariablesResolverTrait;
// use ApiPlatform\Util\OperationRequestInitiatorTrait;
// use Psr\Log\LoggerInterface;
// use Symfony\Component\HttpFoundation\Request;
// use Symfony\Component\HttpFoundation\Response;
// use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// final class MainController
// {
//     use OperationRequestInitiatorTrait;
//     use UriVariablesResolverTrait;
//
//     public function __construct(
//         ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory,
//         private readonly ProviderInterface $provider,
//         private readonly ProcessorInterface $processor,
//         UriVariablesConverterInterface $uriVariablesConverter = null,
//         private readonly ?LoggerInterface $logger = null
//     ) {
//         $this->resourceMetadataCollectionFactory = $resourceMetadataCollectionFactory;
//         $this->uriVariablesConverter = $uriVariablesConverter;
//     }
//
//     public function __invoke(Request $request): Response
//     {
//         $operation = $this->initializeOperation($request);
//         $uriVariables = [];
//         try {
//             $uriVariables = $this->getOperationUriVariables($operation, $request->attributes->all(), $operation->getClass());
//         } catch (InvalidIdentifierException|InvalidUriVariableException $e) {
//             throw new NotFoundHttpException('Invalid uri variables.', $e);
//         }
//
//         $context = [
//             'request' => &$request,
//             'uri_variables' => $uriVariables,
//             'resource_class' => $operation->getClass(),
//         ];
//
//         if (null === $operation->canValidate()) {
//             $operation = $operation->withValidate(!$request->isMethodSafe() && !$request->isMethod('DELETE'));
//         }
//
//         $body = $this->provider->provide($operation, $uriVariables, $context);
//
//         // The provider can change the Operation, extract it again from the Request attributes
//         if ($request->attributes->get('_api_operation') !== $operation) {
//             $operation = $this->initializeOperation($request);
//             try {
//                 $uriVariables = $this->getOperationUriVariables($operation, $request->attributes->all(), $operation->getClass());
//             } catch (InvalidIdentifierException|InvalidUriVariableException $e) {
//                 // if this occurs with our base operation we throw above so log instead of throw here
//                 if ($this->logger) {
//                     $this->logger->error($e->getMessage(), ['operation' => $operation]);
//                 }
//             }
//         }
//
//         $context['previous_data'] = $request->attributes->get('previous_data');
//         $context['data'] = $request->attributes->get('data');
//
//         if (null === $operation->canWrite()) {
//             $operation = $operation->withWrite(!$request->isMethodSafe());
//         }
//
//         return $this->processor->process($body, $operation, $uriVariables, $context);
//     }
// }
//
