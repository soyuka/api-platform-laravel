<?php

namespace App\Providers;

use ApiPlatform\Api\IdentifiersExtractor;
use ApiPlatform\Api\IriConverterInterface;
use ApiPlatform\Hydra\Serializer\CollectionFiltersNormalizer;
use ApiPlatform\Hydra\Serializer\PartialCollectionViewNormalizer;
use ApiPlatform\Hal\Serializer\CollectionNormalizer as HalCollectionNormalizer;
use ApiPlatform\Hal\Serializer\EntrypointNormalizer as HalEntrypointNormalizer;
use ApiPlatform\Hal\Serializer\ItemNormalizer as HalItemNormalizer;
use ApiPlatform\Hal\Serializer\ObjectNormalizer as HalObjectNormalizer;
use ApiPlatform\Hydra\Serializer\CollectionNormalizer as HydraCollectionNormalizer;
use ApiPlatform\Hydra\Serializer\ConstraintViolationListNormalizer as HydraConstraintViolationListNormalizer;
use ApiPlatform\Hydra\Serializer\DocumentationNormalizer as HydraDocumentationNormalizer;
use ApiPlatform\Hydra\Serializer\EntrypointNormalizer as HydraEntrypointNormalizer;
use ApiPlatform\Hydra\Serializer\ErrorNormalizer as HydraErrorNormalizer;
use ApiPlatform\JsonLd\Serializer\ItemNormalizer as JsonLdItemNormalizer;
use ApiPlatform\JsonLd\Serializer\ObjectNormalizer as JsonLdObjectNormalizer;
use ApiPlatform\JsonLd\ContextBuilder as JsonLdContextBuilder;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Operation\UnderscorePathSegmentNameGenerator;
use ApiPlatform\Metadata\Property\Factory\PropertyInfoPropertyNameCollectionFactory;
use ApiPlatform\Metadata\Property\Factory\SerializerPropertyMetadataFactory;
use ApiPlatform\Metadata\Property\Factory\PropertyInfoPropertyMetadataFactory;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Api\ResourceClassResolver;
use ApiPlatform\Metadata\Operation\Factory\OperationMetadataFactory;
use ApiPlatform\Metadata\Operation\Factory\OperationMetadataFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\AttributesResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\LinkFactory;
use ApiPlatform\Metadata\Resource\Factory\AttributesResourceNameCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\PhpDocResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\InputOutputResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\FormatsResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\FiltersResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\NotExposedOperationResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\LinkResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\UriTemplateResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\OperationNameResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\AlternateUriResourceMetadataCollectionFactory;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Problem\Serializer\ErrorNormalizer;
use ApiPlatform\Serializer\JsonEncoder;
use ApiPlatform\Serializer\Mapping\Factory\ClassMetadataFactory as FactoryClassMetadataFactory;
use ApiPlatform\Serializer\SerializerContextBuilder;
use ApiPlatform\State\CallableProvider;
use ApiPlatform\State\Processor\RespondProcessor;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Provider\ContentNegotiationProvider;
use ApiPlatform\Symfony\Messenger\Metadata\MessengerResourceMetadataCollectionFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Negotiation\Negotiator;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class SerializeProcessor implements ProcessorInterface {
  public function __construct(private readonly ProcessorInterface $processor)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        if (!$data instanceof Model || $data instanceof Response || !($operation->canSerialize() ?? true) || !($request = $context['request'] ?? null)) {
            return $this->processor->process($data, $operation, $uriVariables, $context);
        }

        return $this->processor->process($data->toJson(), $operation, $uriVariables, $context);
    }
}

final class FilterLocator implements ContainerInterface
{
    private $filters = [];
    public function get(string $id) {
        return $this->filters[$id] ?? null;
    }

    public function has(string $id): bool {
        return isset($this->filter[$id]);
    }
}


class ApiPlatformProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // TODO these should be in a laravel configuraiton see https://laravel.com/docs/10.x/packages#main-content
        $debug = true;
        $defaultContext = [];
        $patchFormats = ['json' => ['application/merge-patch+json'], 'jsonapi' => ['application/vnd.api+json']];
        $formats = ['jsonld' => ['application/ld+json']];
        $errorFormats = [
            'jsonproblem' => ['application/problem+json'],
            'jsonld' => ['application/ld+json'],
            'jsonapi' => ['application/vnd.api+json']
        ];

        $configuration = [
            'collection' => [
                'pagination' => [
                    'page_parameter_name' => 'page',
                    'enabled_parameter_name' => 'pagination'
                ]
            ]
        ];

        // TODO
        $logger = null;

        $phpDocExtractor = new PhpDocExtractor();
        $reflectionExtractor = new ReflectionExtractor();

        $propertyInfo = new PropertyInfoExtractor(
            [$reflectionExtractor],
            [$phpDocExtractor, $reflectionExtractor],
            [$phpDocExtractor],
            [$reflectionExtractor],
            [$reflectionExtractor]
        );


        $classMetadataFactory = new ClassMetadataFactory(new AnnotationLoader());

        $filterLocator = new FilterLocator();
        $pathSegmentNameGenerator = new UnderscorePathSegmentNameGenerator();

        $resourceNameCollectionFactory = new AttributesResourceNameCollectionFactory([app_path()]);
        $resourceClassResolver = new ResourceClassResolver($resourceNameCollectionFactory);
        $propertyMetadataFactory = new PropertyInfoPropertyMetadataFactory($propertyInfo);
        $propertyMetadataFactory = new SerializerPropertyMetadataFactory(new FactoryClassMetadataFactory($classMetadataFactory), $propertyMetadataFactory, $resourceClassResolver);

        $propertyNameCollectionFactory = new PropertyInfoPropertyNameCollectionFactory($propertyInfo);
        $linkFactory = new LinkFactory(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $resourceClassResolver
        );
        $resourceMetadataFactory = new MessengerResourceMetadataCollectionFactory(
            new AlternateUriResourceMetadataCollectionFactory(
                new FiltersResourceMetadataCollectionFactory(
                    new FormatsResourceMetadataCollectionFactory(
                        new InputOutputResourceMetadataCollectionFactory(
                            new PhpDocResourceMetadataCollectionFactory(
                                new OperationNameResourceMetadataCollectionFactory(
                                    new LinkResourceMetadataCollectionFactory(
                                        $linkFactory,
                                        new UriTemplateResourceMetadataCollectionFactory(
                                            $linkFactory,
                                            $pathSegmentNameGenerator,
                                            new NotExposedOperationResourceMetadataCollectionFactory(
                                                $linkFactory,
                                                new AttributesResourceMetadataCollectionFactory(null, $logger, [], false)
                                            )
                                        )
                                    )
                                )
                            )
                        ),
                        $formats,
                        $patchFormats,
                    )
                )
            )
        );

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $identifiersExtractor = new IdentifiersExtractor($resourceMetadataFactory, $resourceClassResolver, $propertyNameCollectionFactory, $propertyMetadataFactory, $propertyAccessor);

        $this->app->instance(ResourceNameCollectionFactoryInterface::class, $resourceNameCollectionFactory);
        $this->app->instance(ResourceMetadataCollectionFactoryInterface::class, $resourceMetadataFactory);

        $operationMetadataFactory = new OperationMetadataFactory($resourceNameCollectionFactory, $resourceMetadataFactory);
        $this->app->instance(OperationMetadataFactoryInterface::class, $operationMetadataFactory);

        $this->app->bind(CallableProvider::class, function (Application $app) {
            return new CallableProvider($app);
        });

        $this->app->bind(ProviderInterface::class, CallableProvider::class);

        $this->app->extend(CallableProvider::class, function (CallableProvider $inner, Application $app) {
            return new ContentNegotiationProvider($inner, new Negotiator(), ['json' => ['application/json']]);
        });

        $this->app->bind(RespondProcessor::class, function (Application $app) {
            return new RespondProcessor();
        });

        // $this->app->instance(Serializer::class, new Serializer([new ObjectNormalizer()], [new JsonEncoder('json')]));
        // $this->app->bind(SerializerContextBuilderInterface::class, SerializerContextBuilder::class);
        $this->app->extend(RespondProcessor::class, function (RespondProcessor $inner, Application $app) {
            return new SerializeProcessor($inner);
        });

        $this->app->bind(ProcessorInterface::class, RespondProcessor::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    private function getSerializer(ResourceMetadataCollectionFactoryInterface $resourceMetadataFactory, ClassMetadataFactoryInterface $classMetadataFactory, PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory, PropertyMetadataFactoryInterface $propertyMetadataFactory, IriConverterInterface $iriConverter, array $defaultContext, array $configuration) {
        $serializerContextBuilder = new SerializerContextBuilder($resourceMetadataFactory);

        $objectNormalizer = new ObjectNormalizer();

        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
        $jsonLdContextBuilder = new JsonLdContextBuilder($resourceNameCollectionFactory, $resourceMetadataFactory, $propertyNameCollectionFactory, $propertyMetadataFactory, $apiUrlGenerator, $iriConverter, $nameConverter);
        $jsonLdItemNormalizer = new JsonLdItemNormalizer($resourceMetadataFactory, $propertyNameCollectionFactory, $propertyMetadataFactory, $iriConverter, $resourceClassResolver, $jsonLdContextBuilder, $propertyAccessor, $nameConverter, $classMetadataFactory, $defaultContext, /** resource access checker **/ null);
        $jsonLdObjectNormalizer = new JsonLdObjectNormalizer($objectNormalizer, $iriConverter, $jsonLdContextBuilder);
        $jsonLdEncoder = new JsonLdEncoder('jsonld', new JsonEncoder());

        $problemConstraintViolationListNormalizer = new ProblemConstraintViolationListNormalizer([], $nameConverter, $defaultContext);

        $hydraCollectionNormalizer = new HydraCollectionNormalizer($jsonLdContextBuilder, $resourceClassResolver, $iriConverter, $resourceMetadataFactory, $defaultContext);
        $hydraPartialCollectionNormalizer = new PartialCollectionViewNormalizer($hydraCollectionNormalizer, $configuration['collection']['pagination']['page_parameter_name'], $configuration['collection']['pagination']['enabled_parameter_name'], $resourceMetadataFactory, $propertyAccessor);
        $hydraCollectionFiltersNormalizer = new CollectionFiltersNormalizer($hydraPartialCollectionNormalizer, $resourceMetadataFactory, $resourceClassResolver, $filterLocator);
        $hydraErrorNormalizer = new HydraErrorNormalizer($apiUrlGenerator, $debug, $defaultContext);
        $hydraEntrypointNormalizer = new HydraEntrypointNormalizer($resourceMetadataFactory, $iriConverter, $apiUrlGenerator);
        $hydraDocumentationNormalizer = new HydraDocumentationNormalizer($resourceMetadataFactory, $propertyNameCollectionFactory, $propertyMetadataFactory, $resourceClassResolver, $apiUrlGenerator, $nameConverter);
        $hydraConstraintViolationNormalizer = new HydraConstraintViolationListNormalizer($apiUrlGenerator, [], $nameConverter);

        $problemErrorNormalizer = new ErrorNormalizer($debug, $defaultContext);

        // $expressionLanguage = new ExpressionLanguage();
        // $resourceAccessChecker = new ResourceAccessChecker(
        //      $expressionLanguage,
        // );

        $itemNormalizer = new ItemNormalizer(
            $propertyNameCollectionFactory,
            $propertyMetadataFactory,
            $iriConverter,
            $resourceClassResolver,
            $propertyAccessor,
            $nameConverter,
            $classMetadataFactory,
            $logger,
            $resourceMetadataFactory,
            /**$resourceAccessChecker **/ null,
            $defaultContext
        );

        $arrayDenormalizer = new ArrayDenormalizer();
        $problemNormalizer = new ProblemNormalizer($debug, $defaultContext);
        $jsonserializableNormalizer = new JsonSerializableNormalizer($classMetadataFactory, $nameConverter, $defaultContext);
        $dateTimeNormalizer = new DateTimeNormalizer($defaultContext);
        $dataUriNormalizer = new DataUriNormalizer();
        $dateIntervalNormalizer = new DateIntervalNormalizer($defaultContext);
        $dateTimeZoneNormalizer = new DateTimeZoneNormalizer();
        $constraintViolationListNormalizer = new ConstraintViolationListNormalizer($defaultContext, $nameConverter);
        $unwrappingDenormalizer = new UnwrappingDenormalizer($propertyAccessor);

        $halItemNormalizer = new HalItemNormalizer($propertyNameCollectionFactory, $propertyMetadataFactory, $iriConverter, $resourceClassResolver, $propertyAccessor, $nameConverter, $classMetadataFactory, $defaultContext, $resourceMetadataFactory, /** resourceAccessChecker **/ null);
        $halItemNormalizer = new HalItemNormalizer($propertyNameCollectionFactory, $propertyMetadataFactory, $iriConverter, $resourceClassResolver, $propertyAccessor, $nameConverter, $classMetadataFactory, $defaultContext, $resourceMetadataFactory, /** resourceAccessChecker **/ null);

        $halEntrypointNormalizer = new HalEntrypointNormalizer($resourceMetadataFactory, $iriConverter, $apiUrlGenerator);
        $halCollectionNormalizer = new HalCollectionNormalizer($resourceClassResolver, $configuration['collection']['pagination']['page_parameter_name'], $resourceMetadataFactory);
        $halObjectNormalizer = new HalObjectNormalizer($objectNormalizer, $iriConverter);

        $openApiNormalizer = new OpenApiNormalizer($objectNormalizer);

        $list = new \SplPriorityQueue();
        $list->insert($unwrappingDenormalizer, 1000);
        $list->insert($halItemNormalizer, -890);
        $list->insert($hydraConstraintViolationNormalizer, -780);
        $list->insert($hydraEntrypointNormalizer, -800);
        $list->insert($hydraErrorNormalizer, -800);
        $list->insert($hydraCollectionFiltersNormalizer, -800);
        $list->insert($halEntrypointNormalizer, -800);
        $list->insert($halCollectionNormalizer, -985);
        $list->insert($halObjectNormalizer, -995);
        $list->insert($jsonLdItemNormalizer, -890);
        $list->insert($problemConstraintViolationListNormalizer, -780);
        $list->insert($problemErrorNormalizer, -810);
        $list->insert($jsonLdObjectNormalizer, -995);
        $list->insert($constraintViolationListNormalizer, -915);
        $list->insert($arrayDenormalizer, -990);
        $list->insert($dateTimeZoneNormalizer, -915);
        $list->insert($dateIntervalNormalizer, -915);
        $list->insert($dataUriNormalizer, -920);
        $list->insert($dateTimeNormalizer, -910);
        $list->insert($jsonserializableNormalizer, -900);
        $list->insert($problemNormalizer, -890);
        $list->insert($objectNormalizer, -1000);
        $list->insert($itemNormalizer, -895);
        // $list->insert($uuidDenormalizer, -895); //Todo ramsey uuid support ?
        $list->insert($openApiNormalizer, -780);
    }
}
