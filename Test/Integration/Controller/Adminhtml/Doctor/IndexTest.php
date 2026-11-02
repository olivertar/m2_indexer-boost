<?php
/**
 * Copyright © Orangecat
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Orangecat\IndexerBoost\Test\Integration\Controller\Adminhtml\Doctor;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Data\Form\FormKey;
use Magento\TestFramework\Request;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * System > Tools > Indexer Boost; ACL access and denial are tested by the parent class
 */
class IndexTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $resource = 'Orangecat_IndexerBoost::doctor';

    /**
     * @var string
     */
    protected $uri = 'backend/indexerboost/doctor/index';

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_GET;

    public function testPageShowsLiveChecksSavedReportAndRunLog(): void
    {
        $this->request()->setMethod(HttpRequest::METHOD_GET);
        $this->dispatch($this->uri);

        $body = $this->response()->getBody();
        $this->assertSame(200, $this->response()->getHttpResponseCode());
        $this->assertStringContainsString('Light checks (run now)', $body);
        $this->assertStringContainsString('Other checks (last full CLI run)', $body);
        $this->assertStringContainsString('Passive run log', $body);
        $this->assertStringContainsString('INDEXER_STATE', $body);
    }

    public function testPostIsRejected(): void
    {
        // With a valid form key the request reaches the router, which only offers this action for GET
        $this->request()->setMethod(HttpRequest::METHOD_POST);
        $this->request()->setPostValue('form_key', $this->_objectManager->get(FormKey::class)->getFormKey());
        $this->dispatch($this->uri);

        // assert404NotFound() expects the storefront page; the admin renders "404 Error"
        $this->assertSame('noroute', $this->request()->getControllerName());
        $this->assertStringContainsString('404 Error', $this->response()->getBody());
        $this->assertStringNotContainsString('Light checks (run now)', $this->response()->getBody());
    }

    /**
     * Request of the test application
     *
     * @return Request
     */
    private function request(): Request
    {
        $request = $this->getRequest();
        $this->assertInstanceOf(Request::class, $request);
        return $request;
    }

    /**
     * Response of the test application
     *
     * @return Response
     */
    private function response(): Response
    {
        $response = $this->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        return $response;
    }
}
