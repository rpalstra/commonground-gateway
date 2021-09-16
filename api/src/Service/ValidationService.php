<?php

namespace App\Service;

use App\Entity\Attribute;
use App\Entity\ObjectEntity;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;

class ValidationService
{
    private EntityManagerInterface $em;
    private CommonGroundService $commonGroundService;
    private GatewayService $gatewayService;
    private CacheInterface $cache;
    public $promises = []; //TODO: use ObjectEntity->promises instead!

    public function __construct(
        EntityManagerInterface $em,
        CommonGroundService $commonGroundService,
        GatewayService $gatewayService,
        CacheInterface $cache)
    {
        $this->em = $em;
        $this->commonGroundService = $commonGroundService;
        $this->gatewayService = $gatewayService;
        $this->cache = $cache;
    }

    /** TODO:
     * @param ObjectEntity $objectEntity
     * @param array $post
     * @return ObjectEntity
     * @throws Exception
     */
    public function validateEntity (ObjectEntity $objectEntity, array $post): ObjectEntity
    {
        $entity = $objectEntity->getEntity();
        foreach($entity->getAttributes() as $attribute) {
            // Check if we have a value to validate ( a value is given in the post body for this attribute, can be null )
            if (key_exists($attribute->getName(), $post)) {
                $objectEntity = $this->validateAttribute($objectEntity, $attribute, $post[$attribute->getName()]);
            }
            // Check if a defaultValue is set (TODO: defaultValue should maybe be a Value object, so that defaultValue can be something else than a string)
            elseif ($attribute->getDefaultValue()) {
                $objectEntity->getValueByAttribute($attribute)->setValue($attribute->getDefaultValue());
            }
            // Check if this field is nullable
            elseif ($attribute->getNullable()) {
                $objectEntity->getValueByAttribute($attribute)->setValue(null);
            }
            // Check if this field is required
            elseif ($attribute->getRequired()){
                $objectEntity->addError($attribute->getName(),'this attribute is required');
            } else {
                // handling the setting to null of exisiting variables
                $objectEntity->getValueByAttribute($attribute)->setValue(null);
            }
        }

        // Dit is de plek waarop we weten of er een api call moet worden gemaakt
        if(!$objectEntity->getHasErrors() && $objectEntity->getEntity()->getGateway()){
            $promise = $this->createPromise($objectEntity, $post);
            $this->promises[] = $promise;
            $objectEntity->addPromise($promise);
        }

        return $objectEntity;
    }

    /** TODO:
     * @param ObjectEntity $objectEntity
     * @param Attribute $attribute
     * @param $value
     * @return ObjectEntity
     * @throws Exception
     */
    private function validateAttribute(ObjectEntity $objectEntity, Attribute $attribute, $value): ObjectEntity
    {
        // TODO: check if value is null, and if so, should we continue other validations? (!$attribute->getNullable())
        // TODO: something with defaultValue
        // TODO: something with unique

        if ($attribute->getMultiple()) {
            // If multiple, this is an array, validation for an array:
            if (!is_array($value)) {
                $objectEntity->addError($attribute->getName(),'Expects ' . $attribute->getType() . ', ' . gettype($value) . ' given. (Multiple is set for this value)');
            }
            if ($attribute->getMinItems() && count($value) < $attribute->getMinItems()) {
                $objectEntity->addError($attribute->getName(),'The minimum array length of this attribute is ' . $attribute->getMinItems() . '.');
            }
            if ($attribute->getMaxItems() && count($value) > $attribute->getMaxItems()) {
                $objectEntity->addError($attribute->getName(),'The maximum array length of this attribute is ' . $attribute->getMaxItems() . '.');
            }
            if ($attribute->getUniqueItems() && count(array_filter(array_keys($value), 'is_string')) == 0) {
                // TODOmaybe:check this in another way so all kinds of arrays work with it.
                $containsStringKey = false;
                foreach ($value as $arrayItem) {
                    if (is_array($arrayItem) && count(array_filter(array_keys($arrayItem), 'is_string')) > 0){
                        $containsStringKey = true; break;
                    }
                }
                if (!$containsStringKey && count($value) !== count(array_unique($value))) {
                    $objectEntity->addError($attribute->getName(),'Must be an array of unique items');
                }
            }

            // Then validate all items in this array
            if ($attribute->getType() != 'object') {
                foreach($value as $item) {
                    $objectEntity = $this->validateAttributeType($objectEntity, $attribute, $item);
                    $objectEntity = $this->validateAttributeFormat($objectEntity, $attribute, $value);
                }
            } else {
                // TODO: maybe move an merge all this code to the validateAttributeType function under type 'object'. NOTE: this code works very different!!!
                // This is an array of objects
                $valueObject = $objectEntity->getValueByAttribute($attribute);
                foreach($value as $object) {
                    if (!is_array($object)) {
                        $objectEntity->addError($attribute->getName(),'Multiple is set for this value. Expecting an array of objects.');
                        break;
                    }
                    if(array_key_exists('id', $object)) {
                        $subObject = $objectEntity->getValueByAttribute($attribute)->getObjects()->get($object['id']);
                    }
                    else {
                        $subObject = New ObjectEntity();
                        $subObject->setSubresourceOf($valueObject);
                        $subObject->setEntity($attribute->getObject());
                    }
                    $subObject = $this->validateEntity($subObject, $object);

                    // We need to persist if this is a new ObjectEntity in order to set and getId to generate the uri...
                    $this->em->persist($subObject);
                    $subObject->setUri($this->createUri($subObject->getEntity()->getType(), $subObject->getId()));

                    // if no errors we can add this subObject tot the valueObject array of objects
                    if (!$subObject->getHasErrors()) {
                        $subObject->getValueByAttribute($attribute)->setValue($subObject);
                        $valueObject->addObject($subObject);
                    }
                }
            }
        } else {
            // Multiple == false, so this is not an array

            // TODO validate for enum, here or in validateAttributeType function

            $objectEntity = $this->validateAttributeType($objectEntity, $attribute, $value);
            $objectEntity = $this->validateAttributeFormat($objectEntity, $attribute, $value);
        }

        // if no errors we can set the value (for type object this is already done in validateAttributeType, other types we do it here,
        // because when we use validateAttributeType to validate items in an array, we dont want to set values for that)
        if (!$objectEntity->getHasErrors() && $attribute->getType() != 'object') {
            $objectEntity->getValueByAttribute($attribute)->setValue($value);
        }

        return $objectEntity;
    }

    /** TODO:
     * @param ObjectEntity $objectEntity
     * @param Attribute $attribute
     * @param $value
     * @return ObjectEntity
     * @throws Exception
     */
    private function validateAttributeType(ObjectEntity $objectEntity, Attribute $attribute, $value): ObjectEntity
    {
        // Do validation for attribute depending on its type
        switch ($attribute->getType()) {
            case 'object':
                // lets see if we already have a sub object
                $valueObject = $objectEntity->getValueByAttribute($attribute);

                // Lets see if the object already exists
                if(!$valueObject->getValue()) {
                    $subObject = New ObjectEntity();
                    $subObject->setEntity($attribute->getObject());
                    $subObject->setSubresourceOf($valueObject);
                    $valueObject->setValue($subObject);
                } else {
                    $subObject = $valueObject->getValue();
                }

                // TODO: more validation for type object?
                $subObject = $this->validateEntity($subObject, $value);

                // We need to persist if this is a new ObjectEntity in order to set and getId to generate the uri...
                $this->em->persist($subObject);
                $subObject->setUri($this->createUri($subObject->getEntity()->getType(), $subObject->getId()));

                // if not we can push it into our object
                if (!$objectEntity->getHasErrors()) {
                    $objectEntity->getValueByAttribute($attribute)->setValue($subObject);
                }
                break;
            case 'string':
                if (!is_string($value)) {
                    $objectEntity->addError($attribute->getName(),'Expects ' . $attribute->getType() . ', ' . gettype($value) . ' given.');
                }
                if ($attribute->getMinLength() && strlen($value) < $attribute->getMinLength()) {
                    $objectEntity->addError($attribute->getName(),'Is to short, minimum length is ' . $attribute->getMinLength() . '.');
                }
                if ($attribute->getMaxLength() && strlen($value) > $attribute->getMaxLength()) {
                    $objectEntity->addError($attribute->getName(),'Is to long, maximum length is ' . $attribute->getMaxLength() . '.');
                }
                break;
            case 'number':
                if (!is_integer($value) && !is_float($value) && gettype($value) != 'float' && gettype($value) != 'double') {
                    $objectEntity->addError($attribute->getName(),'Expects ' . $attribute->getType() . ', ' . gettype($value) . ' given.');
                }
                break;
            case 'integer':
                if (!is_integer($value)) {
                    $objectEntity->addError($attribute->getName(),'Expects ' . $attribute->getType() . ', ' . gettype($value) . ' given.');
                }
                if ($attribute->getMinimum()) {
                    if ($attribute->getExclusiveMinimum() && $value <= $attribute->getMinimum()) {
                        $objectEntity->addError($attribute->getName(),'Must be higher than ' . $attribute->getMinimum() . '.');
                    } elseif ($value < $attribute->getMinimum()) {
                        $objectEntity->addError($attribute->getName(),'Must be ' . $attribute->getMinimum() . ' or higher.');
                    }
                }
                if ($attribute->getMaximum()) {
                    if ($attribute->getExclusiveMaximum() && $value >= $attribute->getMaximum()) {
                        $objectEntity->addError($attribute->getName(),'Must be lower than ' . $attribute->getMaximum() . '.');
                    } elseif ($value > $attribute->getMaximum()) {
                        $objectEntity->addError($attribute->getName(),'Must be ' . $attribute->getMaximum() . ' or lower.');
                    }
                }
                if ($attribute->getMultipleOf() && $value % $attribute->getMultipleOf() != 0) {
                    $objectEntity->addError($attribute->getName(),'Must be a multiple of ' . $attribute->getMultipleOf() . ', ' . $value . ' is not a multiple of ' . $attribute->getMultipleOf() . '.');
                }
                break;
            case 'boolean':
                if (!is_bool($value)) {
                    $objectEntity->addError($attribute->getName(),'Expects ' . $attribute->getType() . ', ' . gettype($value) . ' given.');
                }
                break;
            case 'date':
            case 'datetime':
                try {
                    new DateTime($value);
                } catch (Exception $e) {
                    $objectEntity->addError($attribute->getName(),'Expects ' . $attribute->getType() . ', failed to parse string to DateTime.');
                }
                break;
            default:
                $objectEntity->addError($attribute->getName(),'has an an unknown type: [' . $attribute->getType() . ']');
        }

        return $objectEntity;
    }

    /** TODO:
     * @param ObjectEntity $objectEntity
     * @param Attribute $attribute
     * @param $value
     * @return ObjectEntity
     */
    private function validateAttributeFormat(ObjectEntity $objectEntity, Attribute $attribute, $value): ObjectEntity
    {
        // Do validation for attribute depending on its format
        switch ($attribute->getFormat()) {
            case 'email':
                var_dump('email');
                break;
            case 'uuid':
                var_dump('uuid');
                break;
            default:
                $objectEntity->addError($attribute->getName(),'has an an unknown format: [' . $attribute->getFormat() . ']');
        }

        return $objectEntity;
    }

    /** TODO:
     * @param ObjectEntity $objectEntity
     * @param array $post
     * @return PromiseInterface
     */
    function createPromise(ObjectEntity $objectEntity, array $post): PromiseInterface
    {

        // We willen de post wel opschonnen, met andere woorden alleen die dingen posten die niet als in een atrubte zijn gevangen

        $component = $this->gatewayService->gatewayToArray($objectEntity->getEntity()->getGateway());
        $query = [];
        $headers = [];

        if($objectEntity->getUri()){
            $method = 'PUT';
            $url = $objectEntity->getUri();
        }
        else{
            $method = 'POST';
            $url = $objectEntity->getEntity()->getGateway()->getLocation() . '/' . $objectEntity->getEntity()->getEndpoint();
        }

        // do transformation
        if($objectEntity->getEntity()->getTransformations() && !empty($objectEntity->getEntity()->getTransformations())){
            /* @todo use array map to rename key's https://stackoverflow.com/questions/9605143/how-to-rename-array-keys-in-php */
        }

        // If we are depend on subresources on another api we need to wait for those to resolve (we might need there id's for this resoure)
        /* @to the bug of setting the promise on the wrong object blocks this */
        if(!$objectEntity->getHasPromises()){
            Utils::settle($objectEntity->getPromises())->wait();
        }

        // At this point in time we have the object values (becuse this is post vallidation) so we can use those to filter the post
        foreach($objectEntity->getObjectValues() as $value){
            // Lets prefend the posting of values that we store localy
            unset($post[$value->getAttribute()->getName()]);

            // then we can check if we need to insert uri for the linked data of subobjects in other api's
            if($value->getAttribute()->getMultiple() && $value->getObjects()){
                /* @todo this loop in loop is a death sin */
                foreach ($value->getObjects() as $objectToUri){
                    $post[$value->getAttribute()->getName()][] =   $objectToUri->getUri();
                }
            }
            elseif($value->getObjects()->first()){
                $post[$value->getAttribute()->getName()] = $value->getObjects()->first()->getUri();
            }
        }

        $promise = $this->commonGroundService->callService($component, $url, json_encode($post), $query, $headers, true, $method)->then(
            // $onFulfilled
            function ($response) use ($post, $objectEntity, $url) {
                $result = json_decode($response->getBody()->getContents(), true);
                if(array_key_exists('id',$result)){
                    $objectEntity->setUri($url.'/'.$result['id']);
                    $item = $this->cache->getItem('commonground_'.md5($url.'/'.$result['id']));
                }
                else{
                    $objectEntity->setUri($url);
                    $item = $this->cache->getItem('commonground_'.md5($url));
                }
                $objectEntity->setExternalResult($result);

                // Lets stuff this into the cache for speed reasons
                $item->set($result);
                //$item->expiresAt(new \DateTime('tomorrow'));
                $this->cache->save($item);
            },
            // $onRejected
            function ($error) use ($post, $objectEntity ) {
                /* @todo lelijke code */
                if($error->getResponse()){
                    $error = json_decode($error->getResponse()->getBody()->getContents(), true);
                    if($error && array_key_exists('message', $error)){
                        $error_message = $error['message'];
                    }
                    elseif($error && array_key_exists('hydra:description', $error)){
                        $error_message = $error['hydra:description'];
                    }
                    else {
                        $error_message =  $error->getResponse()->getBody()->getContents();
                    }
                    $objectEntity->addError('gateway endpoint on ' . $objectEntity->getEntity()->getName() . ' said', $error_message);
                }
                else {
                    $objectEntity->addError('gateway endpoint on '.$objectEntity->getEntity()->getName().' said', $error->getMessage());
                }
            }
        );

        return $promise;
    }

    /** TODO:
     * @param $type
     * @param $id
     * @return string
     */
    public function createUri($type, $id): string
    {
        //TODO: change this to work better? (known to cause problems) used it to generate the @id / @eav for eav objects (intern and extern objects).
        if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $uri = "https://";
        } else {
            $uri = "http://";
        }
        $uri .= $_SERVER['HTTP_HOST'];
        // if not localhost add /api/v1 ?
        if ($_SERVER['HTTP_HOST'] != 'localhost') {
            $uri .= '/api/v1/eav';
        }
        return $uri . '/object_entities/' . $type . '/' . $id;
    }
}
