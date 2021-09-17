<?php

namespace App\Service;

use App\Entity\Attribute;
use App\Entity\Entity;
use App\Entity\ObjectEntity;
use App\Entity\Value;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use phpDocumentor\Reflection\Types\Integer;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Paginator;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\String\Inflector\EnglishInflector;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;


class GatewayDocumentationService
{
    private EntityManagerInterface $em;
    private Client $client;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->client = New Client();
    }

    /**
     * This functions loop trough the available information per gateway to retrieve paths
     *
     * @return boolean returns true if succcesfull or false on failure
     */
    public function getPaths()
    {
        foreach ($this->em->getRepository('App:Gateway')->findAll() as $gateway){
            if($gateway->getDocumentation()){
                try{
                    // Get the docs
                    $response = $this->client->get($gateway->getDocumentation());
                    $response = $response->getBody()->getContents();
                    // Handle json
                    if($oas = json_decode($response, true)){
                    }
                    // back up for yaml
                    elseif($oas = Yaml::parse($response)){
                    }

                    $gateway->setOas($oas);
                    $gateway->setPaths($this->getPathsFromOas($oas));

                    /* @todo throw error */
                    continue;
                }
                catch (\Guzzle\Http\Exception\BadResponseException $e)
                {
                    $raw_response = explode("\n", $e->getResponse());
                    throw new IDPException(end($raw_response));
                }
                //$oas = $client->;

                //$this->em->persist($gateway);
            }
            continue;
        }

        //$this->em->flush();
    }

    /**
     * Places an schema.yaml and schema.json in the /public/eav folder for use by redoc and swagger
     *
     * @return boolean returns true if succcesfull or false on failure
     */
    public function getPathsFromOas(array $oas): array
    {
        $paths = [];

        foreach($oas['paths'] as $path=>$methods){
            // We dont want to pick up the id endpoint
            if(str_contains($path,'{id}')){continue;}

            // er are going to assume that a post gives the complete opbject, so we are going to use the general post
            if(!array_key_exists('post',$methods)){var_dump("no post method"); continue;}
            if(!array_key_exists('responses',$methods['post'])){var_dump("no responces in method"); continue;} // Wierd stuf, but a api might not have responces
            if(!array_key_exists("201",$methods['post']['responses'])){var_dump("no status 201 in responces"); continue;} //We want the 201 responce (item created)

            $response = $methods['post']['responses']["201"];
            // lets pick up on general schemes
            if(array_key_exists('$ref', $response)){
                $response = $oas['components']['responses'][$this->idFromRef($response['$ref'])];
            }

            if(!array_key_exists("content",$response)){var_dump("no content responces"); continue;} //We want the 201 responce (item created)
            if(!array_key_exists('application/json',$response['content'])){var_dump("no  application/json content in responce list"); continue;} //We want the 201 responce (item created)

            $schema = $response['content']['application/json']['schema'];
            $schema = $this->getSchema($oas,$schema);
            $paths[$path] = $schema;
        }

        var_dump($paths);

        return $paths;
    }

    /**
     * Places an schema.yaml and schema.json in the /public/eav folder for use by redoc and swagger
     *
     * @param the level of recurcion of this function
     *
     * @return boolean returns true if succcesfull or false on failure
     */
    public function getSchema(array $oas, array $schema, int $level = 1): array
    {
        // lets pick up on general schemes
        if(array_key_exists('$ref', $schema)){
            $schema = $oas['components']['schemas'][$this->idFromRef($schema['$ref'])];
        }

        $properties = $schema['properties'];

        /* @todo change this to array filter and clear it up*/
        // We need to check for sub schemes
        foreach($properties as $key=>$property){
            if(array_key_exists('$ref',$property)){
                // we only go 5 levels deep
                if($level > 5){
                    unset($properties[$key]);
                    continue;
                }
                $property = $this->getSchema($oas, $property, $level++);
            }
        }

        return $schema;
    }

    /**
     * Gets the identifier form a OAS 3 $ref reference
     *
     * @param string $ref the OAS 3 reference
     * @return string identiefer
     */
    public function idFromRef(string $ref): string
    {
        $id = explode('/',$ref);
        $id = end($id);

        return $id;
    }

}
