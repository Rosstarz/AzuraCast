<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations;

use App\Acl;
use App\Controller\Api\AbstractApiCrudController;
use App\Entity;
use App\Flysystem\StationFilesystems;
use App\Http\Response;
use App\Http\ServerRequest;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PodcastsController extends AbstractApiCrudController
{
    protected string $entityClass = Entity\Podcast::class;
    protected string $resourceRouteName = 'api:stations:podcast';

    public function __construct(
        EntityManagerInterface $em,
        Serializer $serializer,
        ValidatorInterface $validator,
        protected Entity\Repository\StationRepository $stationRepository,
        protected Entity\Repository\PodcastRepository $podcastRepository
    ) {
        parent::__construct($em, $serializer, $validator);
    }

    /**
     * @OA\Get(path="/station/{station_id}/podcasts",
     *   tags={"Stations: Podcasts"},
     *   description="List all current podcasts.",
     *   @OA\Parameter(ref="#/components/parameters/station_id_required"),
     *   @OA\Response(response=200, description="Success",
     *     @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Api_Podcast"))
     *   ),
     *   @OA\Response(response=403, description="Access denied"),
     *   security={{"api_key": {}}},
     * )
     *
     * @OA\Post(path="/station/{station_id}/podcasts",
     *   tags={"Stations: Podcasts"},
     *   description="Create a new podcast.",
     *   @OA\Parameter(ref="#/components/parameters/station_id_required"),
     *   @OA\RequestBody(
     *     @OA\JsonContent(ref="#/components/schemas/Api_Podcast")
     *   ),
     *   @OA\Response(response=200, description="Success",
     *     @OA\JsonContent(ref="#/components/schemas/Api_Podcast")
     *   ),
     *   @OA\Response(response=403, description="Access denied"),
     *   security={{"api_key": {}}},
     * )
     *
     * @OA\Get(path="/station/{station_id}/podcast/{id}",
     *   tags={"Stations: Podcasts"},
     *   description="Retrieve details for a single podcast.",
     *   @OA\Parameter(ref="#/components/parameters/station_id_required"),
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     description="Podcast ID",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Success",
     *     @OA\JsonContent(ref="#/components/schemas/Api_Podcast")
     *   ),
     *   @OA\Response(response=403, description="Access denied"),
     *   security={{"api_key": {}}},
     * )
     *
     * @OA\Put(path="/station/{station_id}/podcast/{id}",
     *   tags={"Stations: Podcasts"},
     *   description="Update details of a single podcast.",
     *   @OA\RequestBody(
     *     @OA\JsonContent(ref="#/components/schemas/Api_Podcast")
     *   ),
     *   @OA\Parameter(ref="#/components/parameters/station_id_required"),
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     description="Podcast ID",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Success",
     *     @OA\JsonContent(ref="#/components/schemas/Api_Status")
     *   ),
     *   @OA\Response(response=403, description="Access denied"),
     *   security={{"api_key": {}}},
     * )
     *
     * @OA\Delete(path="/station/{station_id}/podcast/{id}",
     *   tags={"Stations: Podcasts"},
     *   description="Delete a single podcast.",
     *   @OA\Parameter(ref="#/components/parameters/station_id_required"),
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     description="Podcast ID",
     *     required=true,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Response(response=200, description="Success",
     *     @OA\JsonContent(ref="#/components/schemas/Api_Status")
     *   ),
     *   @OA\Response(response=403, description="Access denied"),
     *   security={{"api_key": {}}},
     * )
     */

    /**
     * @inheritDoc
     */
    public function listAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();

        $queryBuilder = $this->em->createQueryBuilder()
            ->select('p, pc')
            ->from(Entity\Podcast::class, 'p')
            ->leftJoin('p.categories', 'pc')
            ->where('p.storage_location = :storageLocation')
            ->orderBy('p.title', 'ASC')
            ->setParameter('storageLocation', $station->getPodcastsStorageLocation());

        $searchPhrase = trim($request->getParam('searchPhrase', ''));
        if (!empty($searchPhrase)) {
            $queryBuilder->andWhere('p.title LIKE :title')
                ->setParameter('title', '%' . $searchPhrase . '%');
        }

        return $this->listPaginatedFromQuery($request, $response, $queryBuilder->getQuery());
    }

    public function getAction(
        ServerRequest $request,
        Response $response,
        string $podcast_id
    ): ResponseInterface {
        $station = $request->getStation();
        $record = $this->getRecord($station, $podcast_id);

        if (null === $record) {
            return $response->withStatus(404)
                ->withJson(new Entity\Api\Error(404, __('Record not found!')));
        }

        $return = $this->viewRecord($record, $request);
        return $response->withJson($return);
    }

    public function createAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $station = $request->getStation();

        $record = $this->editRecord(
            $request->getParsedBody(),
            new Entity\Podcast($station->getPodcastsStorageLocation())
        );

        $this->processFiles($request, $record);

        return $response->withJson($this->viewRecord($record, $request));
    }

    public function editAction(
        ServerRequest $request,
        Response $response,
        string $podcast_id
    ): ResponseInterface {
        $podcast = $this->getRecord($request->getStation(), $podcast_id);

        if ($podcast === null) {
            return $response->withStatus(404)
                ->withJson(new Entity\Api\Error(404, __('Record not found!')));
        }

        $this->editRecord($request->getParsedBody(), $podcast);
        $this->processFiles($request, $podcast);

        return $response->withJson(new Entity\Api\Status(true, __('Changes saved successfully.')));
    }

    public function deleteAction(
        ServerRequest $request,
        Response $response,
        string $podcast_id
    ): ResponseInterface {
        $station = $request->getStation();
        $record = $this->getRecord($station, $podcast_id);

        if (null === $record) {
            return $response->withStatus(404)
                ->withJson(new Entity\Api\Error(404, __('Record not found!')));
        }

        $fsStation = new StationFilesystems($station);
        $this->podcastRepository->delete($record, $fsStation->getPodcastsFilesystem());

        return $response->withJson(new Entity\Api\Status(true, __('Record deleted successfully.')));
    }

    /**
     * @param Entity\Station $station
     * @param string $id
     */
    protected function getRecord(Entity\Station $station, string $id): ?object
    {
        return $this->podcastRepository->fetchPodcastForStation($station, $id);
    }

    protected function viewRecord(object $record, ServerRequest $request): mixed
    {
        if (!($record instanceof Entity\Podcast)) {
            throw new \InvalidArgumentException(sprintf('Record must be an instance of %s.', $this->entityClass));
        }

        $isInternal = ('true' === $request->getParam('internal', 'false'));
        $router = $request->getRouter();
        $station = $request->getStation();

        $return = new Entity\Api\Podcast();
        $return->id = $record->getId();
        $return->storage_location_id = $record->getStorageLocation()?->getId();
        $return->title = $record->getTitle();
        $return->link = $record->getLink();
        $return->description = $record->getDescription();
        $return->language = $record->getLanguage();

        $categories = [];
        foreach ($record->getCategories() as $category) {
            $categories[] = $category->getCategory();
        }
        $return->categories = $categories;

        $episodes = [];
        foreach ($record->getEpisodes() as $episode) {
            $episodes[] = $episode->getId();
        }
        $return->episodes = $episodes;

        $return->has_custom_art = (0 !== $record->getArtUpdatedAt());
        $return->art = $router->fromHere(
            route_name: 'api:stations:podcast:art',
            route_params: ['podcast_id' => $record->getId() . '|' . $record->getArtUpdatedAt()],
            absolute: true
        );

        $return->links = [
            'self' => $router->fromHere(
                route_name: $this->resourceRouteName,
                route_params: ['podcast_id' => $record->getId()],
                absolute: !$isInternal
            ),
            'episodes' => $router->fromHere(
                route_name: 'api:stations:podcast:episodes',
                route_params: ['podcast_id' => $record->getId()],
                absolute: !$isInternal
            ),
            'public_episodes' => $router->fromHere(
                route_name: 'public:podcast:episodes',
                route_params: ['podcast_id' => $record->getId()],
                absolute: !$isInternal
            ),
            'public_feed' => $router->fromHere(
                route_name: 'public:podcast:feed',
                route_params: ['podcast_id' => $record->getId()],
                absolute: !$isInternal
            ),
        ];

        $acl = $request->getAcl();

        if ($acl->isAllowed(Acl::STATION_PODCASTS, $station)) {
            $return->links['art'] = $router->fromHere(
                route_name: 'api:stations:podcast:art-internal',
                route_params: ['podcast_id' => $record->getId()],
                absolute: !$isInternal
            );
        }

        return $return;
    }

    protected function fromArray($data, $record = null, array $context = []): object
    {
        return parent::fromArray(
            $data,
            $record,
            array_merge(
                $context,
                [
                    AbstractNormalizer::CALLBACKS => [
                        'categories' => function (array $newCategories, $record): void {
                            if (!($record instanceof Entity\Podcast)) {
                                return;
                            }

                            $categories = $record->getCategories();
                            if ($categories->count() > 0) {
                                foreach ($categories as $existingCategories) {
                                    $this->em->remove($existingCategories);
                                }
                                $categories->clear();
                            }

                            foreach ($newCategories as $category) {
                                $podcastCategory = new Entity\PodcastCategory($record, $category);
                                $this->em->persist($podcastCategory);
                                $categories->add($podcastCategory);
                            }
                        },
                    ],
                ]
            )
        );
    }

    protected function processFiles(
        ServerRequest $request,
        Entity\Podcast $record
    ): void {
        $files = $request->getUploadedFiles();

        $artwork = $files['artwork_file'] ?? null;
        if ($artwork instanceof UploadedFileInterface && UPLOAD_ERR_OK === $artwork->getError()) {
            $this->podcastRepository->writePodcastArt(
                $record,
                $artwork->getStream()->getContents()
            );

            $this->em->persist($record);
            $this->em->flush();
        }
    }
}
