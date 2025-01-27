<?php

// src/Subscriber/AddresGetSubscriber.php

namespace App\Subscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use ApiPlatform\Core\Exception\InvalidArgumentException;
use App\Entity\Adres;
use App\Service\HuidigeBevragingenService;
use App\Service\IndividueleBevragingenService;
use App\Service\KadasterService;
use App\Service\KadasterServiceInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

final class AdresGetSubscriber implements EventSubscriberInterface
{
    private $params;
    private KadasterServiceInterface $kadasterService;
    private $serializer;

    public function __construct(ParameterBagInterface $params, KadasterService $kadasterService, HuidigeBevragingenService $huidigeBevragingenService, IndividueleBevragingenService $individueleBevragingenService, SerializerInterface $serializer)
    {
        $this->params = $params;

        if ($this->params->get('common_ground.components')['bag']['location'] == 'https://bag.basisregistraties.overheid.nl/api/v1/') {
            $this->kadasterService = $kadasterService;
        } elseif (
            $this->params->get('common_ground.components')['bag']['location'] == 'https://api.bag.acceptatie.kadaster.nl/lvbag/individuelebevragingen/v2/' ||
            $this->params->get('common_ground.components')['bag']['location'] == 'https://api.bag.kadaster.nl/lvbag/individuelebevragingen/v2/'
        ) {
            $this->kadasterService = $individueleBevragingenService;
        } else {
            $this->kadasterService = $huidigeBevragingenService;
        }
        $this->serializer = $serializer;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['adres', EventPriorities::PRE_DESERIALIZE],
        ];
    }

    public function adres(RequestEvent $event)
    {
        $path = explode('/', parse_url($event->getRequest()->getUri())['path']);
        $route = $event->getRequest()->attributes->get('_route');
        $method = $event->getRequest()->getMethod();

        // Lats make sure that some one posts correctly
        if (Request::METHOD_GET !== $method || ($route != 'api_adres_get_collection' && !in_array('adressen', $path))) {
            return;
        }
        $contentType = $event->getRequest()->headers->get('accept');
        if (!$contentType) {
            $contentType = $event->getRequest()->headers->get('Accept');
        }
        switch ($contentType) {
            case 'application/json':
                $renderType = 'json';
                break;
            case 'application/ld+json':
                $renderType = 'jsonld';
                break;
            case 'application/hal+json':
                $renderType = 'jsonhal';
                break;
            default:
                $contentType = 'application/hal+json';
                $renderType = 'jsonhal';
        }
        $bagId = null;
        if (($route != 'api_adres_get_collection' && in_array('adressen', $path)) || ($route == 'api_adres_get_collection' && $event->getRequest()->query->has('bagid'))) {
            if (!$event->getRequest()->query->has('bagid')) {
                $bagId = end($path);
            } else {
                $bagId = $event->getRequest()->query->get('bagid');
            }
            $adres = $this->kadasterService->getAdresOnBagId($bagId);

            $response = $this->serializer->serialize(
                $adres,
                $renderType,
                ['enable_max_depth'=> true]
            );

            // Creating a response
            $response = new Response(
                $response,
                Response::HTTP_OK,
                ['content-type' => $contentType]
            );

//            $event->setResponse($response);
            $response->send();
        } else {
            $huisnummer = (int) $event->getRequest()->query->get('huisnummer');
            $postcode = $event->getRequest()->query->get('postcode');
            $huisnummerToevoeging = $event->getRequest()->query->get('huisnummer_toevoeging');
            $bagId = $event->getRequest()->query->get('bagid');

            /* @deprecated */
            if (!$huisnummerToevoeging) {
                $huisnummerToevoeging = $event->getRequest()->query->get('huisnummertoevoeging');
            }
            if ($huisnummerToevoeging && str_replace(' ', '', $huisnummerToevoeging) == '') {
                unset($huisnummerToevoeging);
            }

            // Let clear up the postcode
            $postcode = preg_replace('/\s+/', '', $postcode);
            $postcode = strtoupper($postcode);
            $postcode = trim($postcode);

            /* @deprecated */
            if ($bagId && $bagId != '') {
                $adres = $this->kadasterService->getAdresOnBagId($bagId);
            } else {
                // Even iets van basis valdiatie
                if (!$huisnummer || !is_int($huisnummer)) {
                    throw new InvalidArgumentException(sprintf('Invalid huisnummer: '.$huisnummer));
                }

                if (!$postcode || strlen($postcode) != 6) {
                    throw new InvalidArgumentException(sprintf('Invalid postcode: '.$postcode));
                }

                $adressen = $this->kadasterService->getAdresOnHuisnummerPostcode($huisnummer, $postcode);

                // If a huisnummer_toevoeging is provided we need to do some aditional filtering
                if ($huisnummerToevoeging) {
                    $results = [];
                    foreach ($adressen as $adres) {
                        if (
                            $adres instanceof Adres &&
                            str_replace(' ', '', strtolower($adres->getHuisnummertoevoeging())) == str_replace(' ', '', strtolower($huisnummerToevoeging)) ||
                            strpos(str_replace(' ', '', strtolower($adres->getHuisnummertoevoeging())), str_replace(' ', '', strtolower($huisnummerToevoeging))) !== false
                        ) {
                            $results[] = $adres;
                        }
                    }
                    $adressen = $results;
                }
            }
            switch ($renderType) {
                case 'jsonld':
                    $response['@context'] = '/contexts/Adres';
                    $response['@id'] = '/adressen';
                    $response['@type'] = 'hydra:Collection';
                    $response['hydra:member'] = $adressen;
                    $response['hydra:totalItems'] = count($adressen);
                    break;
                default:
                    $response['adressen'] = $adressen;
                    $response['totalItems'] = count($adressen);
                    $response['itemsPerPage'] = count($adressen);
                    $response['_links'] = $response['_links'] = ['self' => '/adressen?huisnummer='.$huisnummer.'&postcode='.$postcode];
                    break;
            }

            $response = $this->serializer->serialize(
                $response,
                $renderType,
                ['enable_max_depth'=> true]
            );

            // Creating a response
            $response = new Response(
                $response,
                Response::HTTP_OK,
                ['content-type' => $contentType]
            );
//            var_dump($response);
            $event->setResponse($response);
        }
    }
}
