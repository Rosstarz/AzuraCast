<?php

namespace App\Entity\Fixture;

use App\Entity;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class Podcast extends AbstractFixture implements DependentFixtureInterface
{
    public function load(ObjectManager $em): void
    {
        /** @var Entity\Station $station */
        $station = $this->getReference('station');

        $podcastStorage = $station->getPodcastsStorageLocation();

        $podcast = new Entity\Podcast($podcastStorage);

        $podcast->setTitle('The AzuraTest Podcast');
        $podcast->setLink('https://demo.azuracast.com');
        $podcast->setLanguage('en');
        $podcast->setDescription('The unofficial testing podcast for the AzuraCast development team.');
        $em->persist($podcast);

        $category = new Entity\PodcastCategory($podcast, 'Technology');
        $em->persist($category);

        $em->flush();

        $this->setReference('podcast', $podcast);
    }

    /**
     * @return string[]
     */
    public function getDependencies(): array
    {
        return [
            Station::class,
        ];
    }
}
