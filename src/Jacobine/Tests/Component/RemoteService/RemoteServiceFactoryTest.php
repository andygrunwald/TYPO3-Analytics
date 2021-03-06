<?php
/**
 * This file is part of the Jacobine package.
 *
 * (c) Andreas Grunwald <andygrunwald@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jacobine\Tests\Component\RemoteService;

use Jacobine\Component\RemoteService\RemoteServiceFactory;

/**
 * Class RemoteServiceFactoryTest
 *
 * Unit test class for \Jacobine\Component\RemoteService\RemoteServiceFactory
 *
 * @package Jacobine\Tests\Component\RemoteService
 * @author Andy Grunwald <andygrunwald@gmail.com>
 */
class RemoteServiceFactoryTest extends \PHPUnit_Framework_TestCase
{

    public function testFactoryReturnsBrowserObjectAsHttpService()
    {
        $remoteServiceFactory = new RemoteServiceFactory();
        $httpService = $remoteServiceFactory->createHttpService(42);

        $this->assertInstanceOf('Buzz\Browser', $httpService);
    }

    public function testHttpServiceWithCorrectParameters()
    {
        $remoteServiceFactory = new RemoteServiceFactory();
        $httpService = $remoteServiceFactory->createHttpService(42, true, false);

        $client = $httpService->getClient();
        /** @var \Buzz\Client\ClientInterface $client */

        $this->assertEquals(42, $client->getTimeout());
        $this->assertEquals(true, $client->getVerifyPeer());
        $this->assertEquals(false, $client->getIgnoreErrors());
    }
}
