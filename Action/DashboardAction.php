<?php
declare(strict_types=1);

namespace Jadob\Dashboard\Action;

use Closure;
use DateTimeInterface;
use Jadob\Contracts\Dashboard\DashboardContextInterface;
use Jadob\Dashboard\ActionType;
use Jadob\Dashboard\Configuration\Dashboard;
use Jadob\Dashboard\Configuration\DashboardConfiguration;
use Jadob\Dashboard\Configuration\NewObjectConfiguration;
use Jadob\Dashboard\CrudOperationType;
use Jadob\Dashboard\Exception\DashboardException;
use Jadob\Dashboard\ObjectManager\DoctrineOrmObjectManager;
use Jadob\Dashboard\ObjectOperation\ShowOperation;
use Jadob\Dashboard\OperationHandler;
use Jadob\Dashboard\PathGenerator;
use Jadob\Dashboard\QueryStringParamName;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\File;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class DashboardAction
{
    public function __construct(
        protected Environment $twig,
        protected ContainerInterface $container,
        protected DashboardConfiguration $configuration,
        protected DoctrineOrmObjectManager $doctrineOrmObjectManager,
        protected FormFactoryInterface $formFactory,
        protected PathGenerator $pathGenerator,
        protected OperationHandler $operationHandler,
        protected LoggerInterface $logger,
        protected ShowOperation $showOperation
    ) {
    }

    /**
     * @param Request $request
     * @param DashboardContextInterface $context
     * @return Response
     * @throws DashboardException
     * @throws LoaderError
     * @throws ReflectionException
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function __invoke(
        Request $request,
        DashboardContextInterface $context
    ): Response {
        $action = $request->query->get(QueryStringParamName::ACTION);

        if ($action === null) {
            return $this->handleDashboard(
                $this->configuration->getDefaultDashboard(),
                $this->configuration,
                $context,
                $request
            );
        }

        $action === mb_strtolower($action);

        if ($action === ActionType::CRUD) {
            return $this->handleCrudOperation($request, $context);
        }

        if ($action === ActionType::IMPORT) {
            return $this->handleImport($request);
        }

        if ($action === ActionType::OPERATION) {
            return $this->handleOperation($request, $context);
        }

        if ($action === ActionType::BATCH_OPERATION) {
            return $this->handleBatchOperation($request, $context);
        }
    }


    protected function handleBatchOperation(Request $request, DashboardContextInterface $context): Response
    {
        $this->logger->debug('handleOperation invoked');
        $objectFqcn = $request->query->get(QueryStringParamName::OBJECT_NAME);
        $operationName = $request->request->get('operation');
        $managedObjectConfiguration = $this->configuration->getManagedObjectConfiguration($objectFqcn);

        $this->logger->debug('Getting information about operation');
        $operation = $managedObjectConfiguration->getListConfiguration()->getOperation($operationName);
        $this->logger->debug('Getting object from persistence');
        foreach ($request->request->get('id') as $objectId) {
            $object = $this->doctrineOrmObjectManager->getOneById($objectFqcn, $objectId);
            $this->logger->debug(sprintf('Continuing to invoke an operation "%s"', $operationName));
            $this->operationHandler->processOperation($operation, $object, $context);
            $this->logger->debug(sprintf('Operation "%s" invoked, returning to list view.', $operationName));
        }

        if ($request->server->has('HTTP_REFERER')) {
            return new RedirectResponse($request->server->get('HTTP_REFERER'));
        }
        return new RedirectResponse($this->pathGenerator->getPathForObjectList($objectFqcn));
    }
    /**
     * @param Request $request
     * @return Response
     * @throws ReflectionException
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws DashboardException
     */
    protected function handleCrudOperation(Request $request, DashboardContextInterface $context): Response
    {
        $operation = mb_strtolower($request->query->get(QueryStringParamName::CRUD_OPERATION));
        /** @psalm-var class-string $objectFqcn */
        $objectFqcn = $request->query->get(QueryStringParamName::OBJECT_NAME);
        $managedObjectConfiguration = $this->configuration->getManagedObjectConfiguration($objectFqcn);

        if ($operation === CrudOperationType::LIST) {
            $listConfiguration = $managedObjectConfiguration->getListConfiguration();
            if (count($listConfiguration->getFieldsToShow()) === 0) {
                throw new DashboardException(sprintf('There is no fields to show in "%s" object configuration.', $objectFqcn));
            }

            $pageNumber = $request->query->getInt(QueryStringParamName::CRUD_CURRENT_PAGE, 1);
            $resultsPerPage = $listConfiguration->getResultsPerPage();

            $criteria = null;
            /** @var string|null $criteriaName */
            $criteriaName = $request->query->get(QueryStringParamName::LIST_CRITERIA);
            $orderBy = (array) $request->query->get(QueryStringParamName::ORDER_BY, []);

            if ($criteriaName === null) {
                $pagesCount = $this->doctrineOrmObjectManager->getPagesCount($objectFqcn, $resultsPerPage);
                $objects = $this->doctrineOrmObjectManager->read(
                    $objectFqcn,
                    $pageNumber,
                    $resultsPerPage,
                    $orderBy
                );
            } else {
                $objectRepo = $this->doctrineOrmObjectManager->getObjectRepository($objectFqcn);
                $criteria = $managedObjectConfiguration
                    ->getListConfiguration()
                    ->getPredefinedCriteria()[$criteriaName];
                $methodToCall = $criteria->getMethod();

                $objects = $objectRepo->$methodToCall();
                if (!is_array($objects)) {
                    throw new DashboardException('Return from predefined criteria must be an array!');
                }

                $pagesCount = 1;
            }

            $list = [];
            $fieldsToExtract = $listConfiguration->getFieldsToShow();

            if ($criteria === null || (!$criteria->isCustomResultSet())) {
                foreach ($objects as $object) {
                    $objectArray = [];
                    $reflectionObject = new ReflectionClass($object);

                    foreach ($fieldsToExtract as $fieldToExtract) {
                        $prop = $reflectionObject->getProperty($fieldToExtract);
                        $prop->setAccessible(true);

                        $val = $prop->getValue($object);

                        if ($val instanceof DateTimeInterface) {
                            $val = $val->format('Y-m-d H:i:s');
                        }

                        $objectArray[$fieldToExtract] = $val;
                    }

                    $list[] = $objectArray;
                }
            } elseif ($criteria->isCustomResultSet()) {
                $fieldsToExtract = array_keys(reset($objects));
                $list = $objects;
            }


            return new Response(
                $this->twig->render(
                    '@JadobDashboard/crud/list.html.twig', [
                        'current_criteria' => $criteria,
                        'managed_object' => $managedObjectConfiguration,
                        'list' => $list,
                        'objects_list' => $objects,
                        'order_by' => $orderBy,
                        'fields' => $fieldsToExtract,
                        'object_fqcn' => $objectFqcn,
                        'results_per_page' => $resultsPerPage,
                        'current_page' => $pageNumber,
                        'pages_count' => $pagesCount,
                        'operations' => $listConfiguration->getOperations(),
                        'redirects' => $listConfiguration->getRedirects()
                    ]
                )
            );
        }

        if ($operation === CrudOperationType::NEW || $operation === CrudOperationType::EDIT) {
            $isEdit = $operation === CrudOperationType::EDIT;

            $objectConfig = $this->configuration->getManagedObjectConfiguration($objectFqcn);
            if (!$objectConfig->hasNewObjectConfiguration()) {
                throw new DashboardException(
                    sprintf('Object "%s" does not have configuration for new objects.', $objectFqcn)
                );
            }

            /** @var NewObjectConfiguration $newConfiguration */
            $newConfiguration = $objectConfig->getNewObjectConfiguration();
            $object = null;

            if ($isEdit) {
                /** @var string $objectId */
                $objectId = $request->query->get(QueryStringParamName::OBJECT_ID);
                /** @var null|object $object */
                $object = $this->doctrineOrmObjectManager->getOneById($objectFqcn, $objectId);
                if ($object === null) {
                    throw new DashboardException(sprintf('There is no object "%s" with ID "%s"!', $objectFqcn, $objectId));
                }
            }

            if ($newConfiguration->getFormFactory() !== null) {
                /** @var Closure $formBuilder */
                $formBuilder = $newConfiguration->getFormFactory();
                /** @var FormInterface|null $form */
                $form = $formBuilder($this->formFactory);
                $form->setData($object);
            } elseif ($newConfiguration->getFormClass() !== null) {
                /** @var string $formClass */
                $formClass = $newConfiguration->getFormClass();
                $form = $this->formFactory->create($formClass, $object);
            } else {
                throw new DashboardException('There is no way to create a form.');
            }

            if ($form === null) {
                throw new RuntimeException('Form factory does not returned a Form!');
            }

            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var object $createdObject */
                $createdObject = $form->getData();

                if ($newConfiguration->hasFormTransformHook()) {
                    $formTransformHook = $newConfiguration->getFormTransformHook();
                    $createdObject = $formTransformHook($createdObject);

                    if (is_object($createdObject) === false) {
                        throw new LogicException(
                            sprintf(
                                '"form_transport_hook" for object %s must return an object.',
                                $objectFqcn
                            )
                        );
                    }
                }
                if ($newConfiguration->hasBeforeInsertHook()) {
                    /** @var callable $beforeInsertHook */
                    $beforeInsertHook = $newConfiguration->getBeforeInsertHook();
                    $beforeInsertHook($createdObject, $form);
                }

                $this->getObjectManagerForObject($objectFqcn)->persist($createdObject);
                return new RedirectResponse($this->pathGenerator->getPathForObjectList($objectFqcn));
            }

            return new Response(
                $this->twig->render(
                    '@JadobDashboard/crud/new.html.twig', [
                        'form' => $form->createView(),
                        'object_fqcn' => $objectFqcn,
                    ]
                )
            );
        }

        if ($operation === CrudOperationType::SHOW) {
            /** @var string $objectId */
            $objectId = $request->query->get(QueryStringParamName::OBJECT_ID);
            /** @var null|object $object */
            $object = $this->doctrineOrmObjectManager->getOneById($objectFqcn, $objectId);


            $response = $this->showOperation->handle(
                $context,
                $object,
                $objectId
            );

            return $response;
        }
        throw new RuntimeException('JDASH0003: Not implemented yet');
    }

    /**
     * @param Dashboard $dashboard
     * @param DashboardConfiguration $dashboardConfiguration
     * @param DashboardContextInterface $context
     * @param Request $request
     * @return Response
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    protected function handleDashboard(
        Dashboard $dashboard,
        DashboardConfiguration $dashboardConfiguration,
        DashboardContextInterface $context,
        Request $request
    ): Response {
        $requestDate = $context->getRequestDateTime();

        return new Response(
            $this->twig->render(
                '@JadobDashboard/dashboard.html.twig', [
                    'dashboard_name' => sprintf('dashboard-%s', $dashboard->getName()),
                    'dashboard' => $dashboard,
                    'request_date' => $requestDate,
                    'request' => $request
                ]
            )
        );
    }

    /**
     * @param Request $request
     * @return Response
     * @throws LoaderError
     * @throws ReflectionException
     * @throws RuntimeError
     * @throws SyntaxError
     */
    protected function handleImport(Request $request): Response
    {
        $objectFqcn = $request->query->get(QueryStringParamName::OBJECT_NAME);
        $managedObjectConfiguration = $this->configuration->getManagedObjectConfiguration($objectFqcn);

        if (!isset($managedObjectConfiguration['imports']) || count($managedObjectConfiguration['imports']) === 0) {
            throw new RuntimeException('There is no import configured for this managed object.');
        }

        $imports = $managedObjectConfiguration['imports'];

        $forms = [];
        foreach ($imports as $key => $import) {
            /** @var array $import */
            if ($import['type'] === 'csv_upload') {
                $form['title'] = $import['name'];
                $form['name'] = $key;
                $formObject = $this
                    ->formFactory
                    ->createNamedBuilder($key)
                    ->add('file', FileType::class, [
                        'constraints' => [
                            new File(['mimeTypes' => $import['mime']])
                        ]
                    ])
                    ->add('submit', SubmitType::class)
                    ->getForm();

                $formObject->handleRequest($request);
                if ($formObject->isSubmitted() && $formObject->isValid()) {
                    $this->logger->info('Form submitted, proceeding to process file.');
                    /** @var UploadedFile $uploadedFile */
                    $uploadedFile = $formObject->get('file')->getData();
                    $fileHandler = $uploadedFile->openFile();
                    $firstLine = true;
                    $headers = [];
                    foreach ($fileHandler as $line) {
                        if ($firstLine) {
                            $firstLine = false;
                            $headers = array_flip(str_getcsv($line));
                            $this->logger->info('Found headers in uploaded file.', [
                                'headers' => $headers
                            ]);

                            continue;
                        }

                        $reflectionObject = new ReflectionClass($objectFqcn);
                        $instance = $reflectionObject->newInstanceWithoutConstructor();
                        $csvLine = str_getcsv($line);

                        if (count($csvLine) !== count($headers)) {
                            $this->logger->warning('Error while importing a file: line does not matches headers, line will be skipped.', [
                                'line' => $csvLine,
                                'headers' => $headers
                            ]);
                            continue;
                        }

                        foreach ($import['mapping'] as $csvHeader => $property) {
                            $valueToInsert = $csvLine[$headers[$csvHeader]];
                            $reflectionProp = $reflectionObject->getProperty($property);
                            $reflectionProp->setAccessible(true);
                            $reflectionProp->setValue($instance, $valueToInsert);
                        }

                        if (isset($import['before_insert'])) {
                            if (!($import['before_insert'] instanceof Closure)) {
                                throw new RuntimeException('Could not use before_insert hook as it is not a closure!');
                            }

                            /** @var callable $beforeInsertCallback */
                            $beforeInsertCallback = $import['before_insert'];
                            $beforeInsertCallback($instance);
                        }

                        $this->doctrineOrmObjectManager->persist($instance);

                        if (isset($import['post_insert'])) {
                            if (!($import['post_insert'] instanceof Closure)) {
                                throw new RuntimeException('Could not use before_insert hook as it is not a closure!');
                            }

                            $import['post_insert']($instance);
                        }
                    }

                    $this->logger->info('Finished processing file, redirecting to listing page.');
                    return new RedirectResponse($this->pathGenerator->getPathForObjectList($objectFqcn));
                }

                $form['form'] = $formObject;
                $forms[] = $form;
            }

            if ($import['type'] === 'paste_csv') {
                $form['title'] = $import['name'];
                $form['name'] = $key;
                $formObject = $this
                    ->formFactory
                    ->createNamedBuilder($key)
                    ->add('content', TextareaType::class, [
                    ])
                    ->add('submit', SubmitType::class)
                    ->getForm();

                $formObject->handleRequest($request);
                if ($formObject->isSubmitted() && $formObject->isValid()) {
                    $this->logger->info('Form submitted, proceeding to handle upload.');
                    $content = $formObject->get('content')->getData();
                    $splittedContent = explode(PHP_EOL, $content);

                    $mapping = $import['mapping'];
                    $headers = [];
                    foreach ($splittedContent as $line) {
                        $reflectionObject = new ReflectionClass($objectFqcn);
                        $instance = $reflectionObject->newInstanceWithoutConstructor();
                        $csvLine = str_getcsv($line, $import['separator'] ?? ',');


                        if (count($csvLine) !== count($mapping)) {
                            $this->logger->warning('Error while importing a file: line does not matches headers, line will be skipped.', [
                                'line' => $csvLine,
                                'headers' => $headers
                            ]);
                            continue;
                        }

                        foreach ($mapping as $csvHeader => $property) {
                            $valueToInsert = $csvLine[$csvHeader];
                            $reflectionProp = $reflectionObject->getProperty($property);
                            $reflectionProp->setAccessible(true);
                            $reflectionProp->setValue($instance, $valueToInsert);
                        }

                        if (isset($import['before_insert'])) {
                            if (!($import['before_insert'] instanceof Closure)) {
                                throw new RuntimeException('Could not use before_insert hook as it is not a closure!');
                            }

                            $import['before_insert']($instance);
                        }

                        $this->doctrineOrmObjectManager->persist($instance);

                        if (isset($import['post_insert'])) {
                            if (!($import['post_insert'] instanceof Closure)) {
                                throw new RuntimeException('Could not use before_insert hook as it is not a closure!');
                            }

                            $import['post_insert']($instance);
                        }
                    }

                    $this->logger->info('Finished processing uploaded content, redirecting to listing page.');
                    return new RedirectResponse($this->pathGenerator->getPathForObjectList($objectFqcn));
                }

                $form['form'] = $formObject;
                $forms[] = $form;
            }
        }

        return new Response(
            $this->twig->render(
                '@JadobDashboard/import.html.twig', [
                    'object_fqcn' => $objectFqcn,
                    'forms' => $forms
                ]
            )
        );
    }

    public function handleOperation(Request $request, DashboardContextInterface $context)
    {
        $this->logger->debug('handleOperation invoked');
        $objectFqcn = $request->query->get(QueryStringParamName::OBJECT_NAME);
        $objectId = $request->query->get(QueryStringParamName::OBJECT_ID);
        $operationName = $request->query->get(QueryStringParamName::OPERATION_NAME);
        $managedObjectConfiguration = $this->configuration->getManagedObjectConfiguration($objectFqcn);

        $this->logger->debug('Getting information about operation');
        $operation = $managedObjectConfiguration->getListConfiguration()->getOperation($operationName);
        $this->logger->debug('Getting object from persistence');
        $object = $this->doctrineOrmObjectManager->getOneById($objectFqcn, $objectId);
        $this->logger->debug(sprintf('Continuing to invoke an operation "%s"', $operationName));
        $this->operationHandler->processOperation($operation, $object, $context);
        $this->logger->debug(sprintf('Operation "%s" invoked, returning to list view.', $operationName));

        return new RedirectResponse($this->pathGenerator->getPathForObjectList($objectFqcn));
    }

    protected function getObjectManagerForObject(string $objectFqcn): DoctrineOrmObjectManager
    {
        $customObjectManager = $this->configuration->getManagedObjectConfiguration($objectFqcn);

        if ($customObjectManager->getObjectManager() !== null) {
            return $this->container->get($customObjectManager->getObjectManager());
        }

        return $this->doctrineOrmObjectManager;
    }
}