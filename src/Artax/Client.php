<?php

namespace Artax;

use Amp\ReactorFactory,
    Artax\Parsing\ParserFactory;

class Client implements ObservableClient {
    
    const USER_AGENT = AsyncClient::USER_AGENT;
    
    private $reactor;
    private $asyncClient;
    private $response;
    private $pendingMultiRequests;
    
    function __construct(AsyncClient $ac = NULL) {
        $this->reactor = (new ReactorFactory)->select();
        
        if ($ac) {
            $this->asyncClient = $ac;
        } else {
            $this->asyncClient = new AsyncClient($this->reactor);
        }
    }

    /**
     * @param $uriOrRequest
     * @throws ClientException
     * @return Response
     */
    function request($uriOrRequest) {
        $onResult = function(Response $response) { $this->onResult($response); };
        $onError = function(\Exception $error) { $this->onError($error); };
        
        $this->reactor->once(function() use ($onResult, $onError, $uriOrRequest) {
            $this->asyncClient->request($uriOrRequest, $onResult, $onError);
        });
        
        $this->reactor->run();
        
        $response = $this->response;
        $this->response = NULL;
        
        return $response;
    }
    
    private function onResult(Response $response) {
        $this->reactor->stop();
        $this->response = $response;
    }
    
    private function onError(\Exception $e) {
        $this->reactor->stop();
        throw $e;
    }
    
    /**
     * @param array $requests
     * @param callable $onResult
     * @param callable $onError
     * @return void
     */
    function requestMulti(array $requests, callable $onResult, callable $onError) {
        $this->pendingMultiRequests = new \SplObjectStorage;
        $this->onMultiResult = $onResult;
        $this->onMultiError = $onError;
        
        foreach ($this->normalizeMultiRequests($requests) as $requestKey => $request) {
            $onResult = function(Response $response) use ($request) {
                $this->onMultiResult($request, $response);
            };
            $onError = function(\Exception $error) use ($request) {
                $this->onMultiError($request, $error);
            };
            
            $this->reactor->once(function() use ($onResult, $onError, $request) {
                $this->asyncClient->request($request, $onResult, $onError);
            });
            
            $this->pendingMultiRequests->attach($request, $requestKey);
        }
        
        $this->reactor->run();
    }
    
    private function normalizeMultiRequests(array $requests) {
        if (!$requests) {
            throw new \InvalidArgumentException(
                'Request array must not be empty'
            );
        }
        
        $normalized = [];
        
        foreach ($requests as $requestKey => $request) {
            $normalized[$requestKey] = ($request instanceof Request)
                ? $request
                : (new Request)->setUri($request);
        }
        
        return $normalized;
    }
    
    private function onMultiResult(Request $request, Response $response) {
        $requestKey = $this->clearPendingMultiRequest($request);
        $callback = $this->onMultiResult;
        $callback($requestKey, $response);
    }
    
    private function clearPendingMultiRequest(Request $request) {
        $requestKey = $this->pendingMultiRequests->offsetGet($request);
        $this->pendingMultiRequests->detach($request);
        if (!$this->pendingMultiRequests->count()) {
            $this->reactor->stop();
        }
        
        return $requestKey;
    }
    
    private function onMultiError(Request $request, \Exception $error) {
        $requestKey = $this->clearPendingMultiRequest($request);
        $callback = $this->onMultiError;
        $callback($requestKey, $error);
    }
    
    function cancel(Request $request) {
        $this->asyncClient->cancel($request);
        $this->clearPendingMultiRequest($request);
    }
    
    function cancelAll() {
        $this->asyncClient->cancelAll();
        $this->pendingMultiRequests = new \SplObjectStorage;
        $this->reactor->stop();
    }
    
    function setResponse(Request $request, Response $response) {
        return $this->asyncClient->setResponse($request, $response);
    }
    
    function setOption($option, $value) {
        return $this->asyncClient->setOption($option, $value);
    }
    
    function setAllOptions(array $options) {
        return $this->asyncClient->setAllOptions($options);
    }
    
    function subscribe(array $eventListenerMap, $unsubscribeOnError = TRUE) {
        return $this->asyncClient->subscribe($eventListenerMap, $unsubscribeOnError);
    }
    
    function unsubscribe(Subscription $subscription) {
        return $this->asyncClient->unsubscribe($subscription);
    }
    
    function unsubscribeAll() {
        return $this->asyncClient->unsubscribeAll();
    }
    
    function notify($event, $data = NULL) {
        return $this->asyncClient->notify($event, $data);
    }
}

