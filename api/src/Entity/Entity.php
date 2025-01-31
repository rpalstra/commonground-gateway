<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use App\Entity\Gateway as Source;
use App\Exception\GatewayException;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping as ORM;
use Exception;
use Gedmo\Mapping\Annotation as Gedmo;
use phpDocumentor\Reflection\Types\This;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A schema that functions as an object template for objects that might be stored in the EAV database.
 *
 * @ApiResource(
 *  normalizationContext={"groups"={"read"}, "enable_max_depth"=true},
 *  denormalizationContext={"groups"={"write"}, "enable_max_depth"=true},
 *  itemOperations={
 *     "get"={"path"="/admin/entities/{id}"},
 *     "put"={"path"="/admin/entities/{id}"},
 *     "delete"={"path"="/admin/entities/{id}"}
 *  },
 *  collectionOperations={
 *     "get"={"path"="/admin/entities"},
 *     "post"={"path"="/admin/entities"},
 *     "delete_objects"={
 *          "path"="/admin/entities/{id}/delete_objects",
 *          "method"="POST",
 *          "read"=false,
 *          "validate"=false,
 *          "requirements"={
 *              "id"=".+"
 *          },
 *          "openapi_context"={
 *              "summary"="Delete Objects for this Schema",
 *              "description"="Deletes all objects that belong to this schema"
 *          }
 *      },
 *  })
 *
 * @ORM\Entity(repositoryClass="App\Repository\EntityRepository")
 *
 * @Gedmo\Loggable(logEntryClass="Conduction\CommonGroundBundle\Entity\ChangeLog")
 *
 * @ApiFilter(BooleanFilter::class)
 * @ApiFilter(OrderFilter::class)
 * @ApiFilter(DateFilter::class, strategy=DateFilter::EXCLUDE_NULL)
 * @ApiFilter(SearchFilter::class, properties={
 *     "name": "exact",
 *     "reference": "exact"
 * })
 */
class Entity
{
    /**
     * @var UuidInterface The UUID identifier of this Entity.
     *
     * @Groups({"read"})
     *
     * @ORM\Id
     *
     * @ORM\Column(type="uuid", unique=true)
     *
     * @ORM\GeneratedValue(strategy="CUSTOM")
     *
     * @ORM\CustomIdGenerator(class="Ramsey\Uuid\Doctrine\UuidGenerator")
     */
    private $id;

    /**
     * @Groups({"read","write"})
     *
     * @ORM\ManyToOne(targetEntity=Gateway::class, fetch="EAGER")
     *
     * @ORM\JoinColumn(nullable=true)
     *
     * @MaxDepth(1)
     *
     * @deprecated
     */
    private ?Source $gateway = null;

    /**
     * @var string The type of this Entity
     *
     * @Gedmo\Versioned
     *
     * @Assert\Length(
     *     max = 255
     * )
     *
     * @Groups({"read","write"})
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     *
     * @deprecated
     */
    private $endpoint = '';

    /**
     * @Groups({"read","write"})
     *
     * @ORM\OneToOne(targetEntity=Soap::class, fetch="EAGER", mappedBy="fromEntity")
     *
     * @MaxDepth(1)
     *
     * @deprecated
     */
    private ?Soap $toSoap = null;

    /**
     * @ORM\OneToMany(targetEntity=Soap::class, mappedBy="toEntity", orphanRemoval=true)
     *
     * @deprecated
     */
    private $fromSoap;

    /**
     * @var string The name of this Entity
     *
     * @Gedmo\Versioned
     *
     * @Assert\Length(
     *     max = 255
     * )
     *
     * @Assert\NotNull
     *
     * @Groups({"read","write"})
     *
     * @ORM\Column(type="string", length=255)
     */
    private $name;

    /**
     * @var string The description of this Entity
     *
     * @Gedmo\Versioned
     *
     * @Groups({"read","write"})
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private $description = '';

    /**
     * @var string The function of this Entity. This is used for making specific entity types/functions work differently
     *
     * @example organization
     *
     * @Assert\Choice({"noFunction","organization", "person", "user", "userGroup", "processingLog"})
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="string", options={"default":"noFunction"}, name="function_column")
     *
     * @deprecated
     */
    private string $function = 'noFunction';

    /**
     * @var bool whether the properties of the original object are automatically include.
     *
     * @Groups({"read","write"})
     *
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $extend = false;

    /**
     * Whether objects created from this entity should be available to child organisations.
     *
     * @Groups({"read","write"})
     *
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $inherited = false;

    /**
     * The attributes of this Entity.
     *
     * @Groups({"read","write"})
     *
     * @ORM\OneToMany(targetEntity=Attribute::class, mappedBy="entity", cascade={"persist"}, orphanRemoval=true, fetch="EAGER")
     *
     * @MaxDepth(1)
     */
    private Collection $attributes;

    /**
     * @var Collection|null The attributes allowed to partial search on using the search query parameter.
     *
     * @Groups({"read","write"})
     *
     * @ORM\OneToMany(targetEntity=Attribute::class, mappedBy="searchPartial", fetch="EAGER")
     *
     * @MaxDepth(1)
     *
     * @deprecated
     */
    private ?Collection $searchPartial;

    /**
     * @Groups({"write"})
     *
     * @ORM\OneToMany(targetEntity=ObjectEntity::class, mappedBy="entity", cascade={"remove"}, fetch="EXTRA_LAZY")
     *
     * @ORM\OrderBy({"dateCreated" = "DESC"})
     *
     * @MaxDepth(1)
     */
    private Collection $objectEntities;

    /**
     * @Groups({"write"})
     *
     * @ORM\OneToMany(targetEntity=Attribute::class, mappedBy="object", fetch="EXTRA_LAZY")
     *
     * @MaxDepth(1)
     */
    private Collection $usedIn;

    /**
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="array", nullable=true)
     *
     * @deprecated
     */
    private ?array $transformations = [];

    /**
     * @var string|null The route this entity can be found easier
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     *
     * @deprecated
     */
    private ?string $route = null;

    /**
     * @var array|null The properties available for this entity (for all CRUD calls) if null all properties will be used. This affects which properties are written to / retrieved from external api's.
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="array", nullable=true)
     *
     * @deprecated
     */
    private ?array $availableProperties = [];

    /**
     * @var array|null The properties used for this entity (for all CRUD calls) if null all properties will be used. This affects which properties will be written / shown.
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="array", nullable=true)
     *
     * @deprecated
     */
    private ?array $usedProperties = [];

    /**
     * @var array Used for ConvertToGatewayService. Config to translate specific calls to a different method or endpoint. When changing the endpoint, if you want, you can use {id} to specify the location of the id in the endpoint.
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="array", nullable=true)
     *
     * @deprecated
     */
    private array $translationConfig = [];

    /**
     * @var array Used for ConvertToGatewayService. Config for getting the results out of a get collection on this endpoint (results and id are required!). "results" for where to find all items, "envelope" for where to find a single item in results, "id" for where to find the id of in a single item and "paginationPages" for where to find the total amount of pages or a reference to the last page (from root). (both envelope and id are from the root of results! So if id is in the envelope example: envelope = instance, id = instance.id)
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="array", nullable=true)
     *
     * @deprecated
     */
    private array $collectionConfig = ['results' => 'hydra:member', 'id' => 'id', 'paginationPages' => 'hydra:view.hydra:last'];

    /**
     * @var array Used for ConvertToGatewayService. Config for getting the body out of a get item on this endpoint. "envelope" for where to find the body. example: envelope => result.instance
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="array", nullable=true)
     *
     * @deprecated
     */
    private array $itemConfig = [];

    /**
     * @var array|null Used for ConvertToGatewayService. The mapping in from extern source to gateway.
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="array", nullable=true)
     *
     * @deprecated
     */
    private ?array $externMappingIn = [];

    /**
     * @var array|null Used for ConvertToGatewayService. The mapping out from gateway to extern source.
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="array", nullable=true)
     *
     * @deprecated
     */
    private ?array $externMappingOut = [];

    /**
     * @var array|null The handlers used for this entity.
     *
     * @MaxDepth(1)
     *
     * @Groups({"write"})
     *
     * @ORM\OneToMany(targetEntity=Handler::class, mappedBy="entity", fetch="EXTRA_LAZY")
     *
     * @deprecated
     */
    private Collection $handlers;

    /**
     * @var ?Collection The collections of this Entity
     *
     * @Groups({"write", "read"})
     *
     * @MaxDepth(1)
     *
     * @ORM\ManyToMany(targetEntity=CollectionEntity::class, mappedBy="entities")
     *
     * @ORM\OrderBy({"dateCreated" = "DESC"})
     */
    private ?Collection $collections;

    /**
     * @var ?string The uri to a schema.org object
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="string", length=255, nullable=true, options={"default":null}, name="schema_column")
     *
     * @deprecated Replaced by reference
     */
    private ?string $schema = null;

    /**
     * @var Datetime The moment this resource was created
     *
     * @Groups({"read"})
     *
     * @Gedmo\Timestampable(on="create")
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $dateCreated;

    /**
     * @var Datetime The moment this resource was last Modified
     *
     * @Groups({"read"})
     *
     * @Gedmo\Timestampable(on="update")
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $dateModified;

    /**
     * @var array|null The properties used to set the name for ObjectEntities created linked to this Entity.
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="array", length=255, nullable=true, options={"default": null})
     */
    private ?array $nameProperties = [];

    /**
     * @var int The maximum depth that should be used when casting objects of this entity to array
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="integer", length=1, options={"default": 3})
     */
    private int $maxDepth = 3;

    /**
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="string", length=255, nullable=true, options={"default": null})
     */
    private ?string $reference = null;

    /**
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="string", length=255, nullable=true, options={"default": null})
     */
    private ?string $version = null;

    //todo: do we want read/write groups here?
    /**
     * @ORM\ManyToMany(targetEntity=Endpoint::class, mappedBy="entities")
     */
    private $endpoints;

    /**
     * @var bool Whether the entity should be excluded from rendering as sub object
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="boolean", options={"default": false}, nullable=true)
     */
    private bool $exclude = false;

    /**
     * @var bool Whether the object of the entity should be persisted to the database
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="boolean", options={"default": true}, nullable=true)
     */
    private bool $persist = true;

    /**
     * @var bool Whether audittrails have to be created for the entity
     *
     * @Groups({"read", "write"})
     *
     * @ORM\Column(type="boolean", options={"default": false}, nullable=true)
     */
    private bool $createAuditTrails = false;

    /**
     * @var Source|null The default source to synchronise to.
     *
     * @Groups({"read", "write"})
     *
     * @ORM\ManyToOne(targetEntity=Gateway::class, cascade={"persist", "remove"})
     */
    private ?Source $defaultSource = null;

    public function __toString()
    {
        return $this->getName().' ('.$this->getId().')';
    }

    public function __construct()
    {
        $this->attributes = new ArrayCollection();
        $this->searchPartial = new ArrayCollection();
        $this->objectEntities = new ArrayCollection();
        $this->usedIn = new ArrayCollection();
        $this->soap = new ArrayCollection();
        $this->handlers = new ArrayCollection();
        $this->collections = new ArrayCollection();
        $this->endpoints = new ArrayCollection();
    }

    public function export()
    {
        if ($this->getSource() !== null) {
            $source = $this->getSource()->getId()->toString();
            $source = '@'.$source;
        } else {
            $source = null;
        }

        $data = [
            'gateway'             => $source,
            'endpoint'            => $this->getEndpoint(),
            'name'                => $this->getName(),
            'description'         => $this->getDescription(),
            'extend'              => $this->getExtend(),
            'transformations'     => $this->getTransformations(),
            'route'               => $this->getRoute(),
            'availableProperties' => $this->getAvailableProperties(),
            'usedProperties'      => $this->getUsedProperties(),
        ];

        return array_filter($data, fn ($value) => !is_null($value) && $value !== '' && $value !== []);
    }

    private const SUPPORTED_VALIDATORS = [
        'multipleOf',
        'maximum',
        'exclusiveMaximum',
        'minimum',
        'exclusiveMinimum',
        'maxLength',
        'minLength',
        'maxItems',
        'uniqueItems',
        'maxProperties',
        'minProperties',
        'required',
        'enum',
        'allOf',
        'oneOf',
        'anyOf',
        'not',
        'items',
        'additionalProperties',
        'default',
    ];

    public function getId()
    {
        return $this->id;
    }

    public function getSource(): ?Source
    {
        return $this->gateway;
    }

    public function setSource(?Source $source): self
    {
        $this->gateway = $source;

        return $this;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getToSoap(): ?Soap
    {
        return $this->toSoap;
    }

    public function setToSoap(?Soap $toSoap): self
    {
        $this->toSoap = $toSoap;

        return $this;
    }

    /**
     * @return Collection|Soap[]
     */
    public function getFromSoap(): Collection
    {
        return $this->fromSoap;
    }

    public function addFromSoap(Soap $fromSoap): self
    {
        if (!$this->fromSoap->contains($fromSoap)) {
            $this->fromSoap[] = $fromSoap;
            $fromSoap->setToEntity($this);
        }

        return $this;
    }

    public function removeFromSoap(Soap $fromSoap): self
    {
        if ($this->fromSoap->removeElement($fromSoap)) {
            // set the owning side to null (unless already changed)
            if ($fromSoap->getToEntity() === $this) {
                $fromSoap->setToEntity(null);
            }
        }

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        // New (example: ObjectEntity will become object_entity)
        // lets make sure this name is slugable
        // $name = trim($name); //removes whitespace at begin and ending
        // $firstChar = strtolower($name[0]); // get first char because we dont want to set a _ before first capital
        // $name = substr($name, 1); // subtract first character
        // $name = preg_replace('/(?<!\ )[A-Z]/', '_$0', $name); // change upper chars to lower and put a _ in front of it
        // $name = $firstChar . strtolower($name); // combine strings

        // Old (example: ObjectEntity would become objectentity)
        // $name = trim($name); //removes whitespace at begin and ending
        // $name = preg_replace('/\s+/', '_', $name); // replaces other whitespaces with _
        // $name = $firstChar . strtolower($name); // combine strings

        $this->name = $name;

        return $this;
    }

    public function getFunction(): ?string
    {
        return $this->function;
    }

    public function setFunction(?string $function): self
    {
        $this->function = $function;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get an value based on a attribut.
     *
     * @param string $name the name of the attribute that you are searching for
     *
     * @return Attribute|bool Iether the found attribute or false if no attribute could be found
     */
    public function getAttributeByName(string $name)
    {
        // Check if value with this attribute exists for this ObjectEntity
        $criteria = Criteria::create()->andWhere(Criteria::expr()->eq('name', $name))->setMaxResults(1);
        $attributes = $this->getAttributes()->matching($criteria);

        if ($attributes->isEmpty()) {
            return false;
        }

        return $attributes->first();
    }

    /**
     * @return Collection|Attribute[]
     */
    public function getAttributes(): Collection
    {
        return $this->attributes;
    }

    public function addAttribute(Attribute $attribute): self
    {
        if (!$this->attributes->contains($attribute)) {
            $this->attributes[] = $attribute;
            $attribute->setEntity($this);
        }

        return $this;
    }

    public function removeAttribute(Attribute $attribute): self
    {
        if ($this->attributes->removeElement($attribute)) {
            // set the owning side to null (unless already changed)
            if ($attribute->getEntity() === $this) {
                $attribute->setEntity(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Attribute[]
     */
    public function getSearchPartial(): Collection
    {
        return $this->searchPartial;
    }

    /**
     * @todo docs
     *
     * @param Attribute $attribute
     *
     * @throws Exception
     *
     * @return $this
     */
    public function addSearchPartial(Attribute $attribute): self
    {
        // Only allow adding to searchPartial if the attribute is part of this Entity.
        // Or if this entity has no attributes, when loading in fixtures.
        if (!$this->searchPartial->contains($attribute)
            && ($this->attributes->isEmpty() || $this->attributes->contains($attribute))
        ) {
            $this->searchPartial[] = $attribute;
            $attribute->setSearchPartial($this);
        } else {
            throw new Exception('You are not allowed to set searchPartial of an Entity to an Attribute that is not part of this Entity.');
        }

        return $this;
    }

    public function removeSearchPartial(Attribute $attribute): self
    {
        if ($this->searchPartial->removeElement($attribute)) {
            // set the owning side to null (unless already changed)
            if ($attribute->getSearchPartial() === $this) {
                $attribute->setSearchPartial(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|ObjectEntity[]
     */
    public function getObjectEntities(): Collection
    {
        return $this->objectEntities;
    }

    public function addObjectEntity(ObjectEntity $objectEntity): self
    {
        if (!$this->objectEntities->contains($objectEntity)) {
            $this->objectEntities[] = $objectEntity;
            $objectEntity->setEntity($this);
        }

        return $this;
    }

    public function removeObjectEntity(ObjectEntity $objectEntity): self
    {
        if ($this->objectEntities->removeElement($objectEntity)) {
            // set the owning side to null (unless already changed)
            if ($objectEntity->getEntity() === $this) {
                $objectEntity->setEntity(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Attribute[]
     */
    public function getUsedIn(): Collection
    {
        return $this->usedIn;
    }

    public function addUsedIn(Attribute $attribute): self
    {
        if (!$this->usedIn->contains($attribute)) {
            $this->usedIn[] = $attribute;
            $attribute->setObject($this);
        }

        return $this;
    }

    public function removeUsedIn(Attribute $attribute): self
    {
        if ($this->usedIn->removeElement($attribute)) {
            // set the owning side to null (unless already changed)
            if ($attribute->getObject() === $this) {
                $attribute->setObject(null);
            }
        }

        return $this;
    }

    public function getTransformations(): ?array
    {
        return $this->transformations;
    }

    public function setTransformations(array $transformations): self
    {
        $this->transformations = $transformations;

        return $this;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function setRoute(?string $route): self
    {
        $this->route = $route;

        return $this;
    }

    public function getAvailableProperties(): ?array
    {
        return $this->availableProperties;
    }

    public function setAvailableProperties(?array $availableProperties): self
    {
        $this->availableProperties = $availableProperties;

        return $this;
    }

    public function getUsedProperties(): ?array
    {
        return $this->usedProperties;
    }

    public function setUsedProperties(?array $usedProperties): self
    {
        $this->usedProperties = $usedProperties;

        return $this;
    }

    public function getExtend(): ?bool
    {
        return $this->extend;
    }

    public function setExtend(?bool $extend): self
    {
        $this->extend = $extend;

        return $this;
    }

    public function getTranslationConfig(): ?array
    {
        return $this->translationConfig;
    }

    public function setTranslationConfig(?array $translationConfig): self
    {
        $this->translationConfig = $translationConfig;

        return $this;
    }

    public function getCollectionConfig(): ?array
    {
        return $this->collectionConfig;
    }

    public function setCollectionConfig(?array $collectionConfig): self
    {
        $this->collectionConfig = $collectionConfig;

        return $this;
    }

    public function getItemConfig(): ?array
    {
        return $this->itemConfig;
    }

    public function setItemConfig(?array $itemConfig): self
    {
        $this->itemConfig = $itemConfig;

        return $this;
    }

    public function getExternMappingIn(): ?array
    {
        return $this->externMappingIn;
    }

    public function setExternMappingIn(?array $externMappingIn): self
    {
        $this->externMappingIn = $externMappingIn;

        return $this;
    }

    public function getExternMappingOut(): ?array
    {
        return $this->externMappingOut;
    }

    public function setExternMappingOut(?array $externMappingOut): self
    {
        $this->externMappingOut = $externMappingOut;

        return $this;
    }

    public function getInherited(): ?bool
    {
        return $this->inherited;
    }

    public function setInherited(?bool $inherited): self
    {
        $this->inherited = $inherited;

        return $this;
    }

    /**
     * @return Collection|Handler[]
     */
    public function getHandlers(): Collection
    {
        return $this->handlers;
    }

    public function addHandler(Handler $handler): self
    {
        if (!$this->handlers->contains($handler)) {
            $this->handlers[] = $handler;
            $handler->setEntity($this);
        }

        return $this;
    }

    public function removeHandler(Handler $handler): self
    {
        if ($this->handlers->removeElement($handler)) {
            // set the owning side to null (unless already changed)
            if ($handler->getEntity() === $this) {
                $handler->setEntity(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|CollectionEntity[]
     */
    public function getCollections(): Collection
    {
        return $this->collections;
    }

    public function addCollection(CollectionEntity $collection): self
    {
        if (!$this->collections->contains($collection)) {
            $this->collections[] = $collection;
            $collection->addEntity($this);
        }

        return $this;
    }

    public function removeCollection(CollectionEntity $collection): self
    {
        if ($this->collections->removeElement($collection)) {
            $collection->removeEntity($this);
        }

        return $this;
    }

    public function getDateCreated(): ?DateTimeInterface
    {
        return $this->dateCreated;
    }

    public function setDateCreated(DateTimeInterface $dateCreated): self
    {
        $this->dateCreated = $dateCreated;

        return $this;
    }

    public function getDateModified(): ?DateTimeInterface
    {
        return $this->dateModified;
    }

    public function setDateModified(DateTimeInterface $dateModified): self
    {
        $this->dateModified = $dateModified;

        return $this;
    }

    public function getSchema(): ?string
    {
        return $this->schema;
    }

    /**
     * @param string|null $schema
     *
     * @return $this This schema
     */
    public function setSchema(?string $schema): self
    {
        $this->schema = $schema;

        return $this;
    }

    /**
     * Create or update this schema from an external schema array.
     *
     * This function is used to update and create schema's form schema.json objects
     *
     * @param array $schema The schema to load.
     *
     * @return $this This schema.
     */
    public function fromSchema(array $schema): self
    {
        // Basic stuff.
        if (array_key_exists('$id', $schema)) {
            $this->setReference($schema['$id']);
            $this->setSchema($schema['$id']);
        }
        if (array_key_exists('title', $schema)) {
            $this->setName($schema['title']);
        }
        if (array_key_exists('description', $schema)) {
            $this->setDescription($schema['description']);
        }
        if (array_key_exists('version', $schema)) {
            $this->setVersion($schema['version']);
        }
        if (array_key_exists('exclude', $schema)) {
            $this->setExclude($schema['exclude']);
        }
        if (array_key_exists('maxDepth', $schema)) {
            $this->setMaxDepth($schema['maxDepth']);
        }
        if (array_key_exists('nameProperties', $schema)) {
            $this->setNameProperties($schema['nameProperties']);
        }

        // Properties.
        if (array_key_exists('properties', $schema)) {
            foreach ($schema['properties'] as $name => $property) {
                // Some properties are considered forbidden.
                if (str_starts_with($name, '_') || str_starts_with($name, '$') || str_starts_with($name, '@')) {
                    continue;
                }

                // Let see if the attribute exists.
                if (!$attribute = $this->getAttributeByName($name)) {
                    $attribute = new Attribute();
                    $attribute->setName($name);
                }
                $this->addAttribute($attribute->fromSchema($property));
            }
        }

        // Required stuff.
        if (array_key_exists('required', $schema)) {
            foreach ($schema['required'] as $required) {
                $attribute = $this->getAttributeByName($required);
                //We can only set the attribute on required if it exists so.
                if ($attribute instanceof Attribute === true) {
                    $attribute->setRequired(true);
                }
            }
        }

        // A bit of cleanup.
        foreach ($this->getAttributes() as $attribute) {
            // Remove Required if no longer valid.
            if (array_key_exists('required', $schema) && !in_array($attribute->getName(), $schema['required']) && $attribute->getRequired() == true) {
                $attribute->setRequired(false);
            }
            // Remove attribute if no longer present.
            if (!array_key_exists($attribute->getName(), $schema['properties'])) {
                $this->removeAttribute($attribute);
            }
        }

        return $this;
    }

    /**
     * Convert this Entity to a schema.
     *
     * @throws GatewayException
     *
     * @return array Schema array.
     */
    public function toSchema(?ObjectEntity $objectEntity = null): array
    {
        $schema = [
            '$id'               => $this->getReference(), //@todo dit zou een interne uri verwijzing moeten zijn maar hebben we nog niet
            '$schema'           => 'https://docs.commongateway.nl/schemas/Entity.schema.json',
            'title'             => $this->getName(),
            'description'       => $this->getDescription(),
            'version'           => $this->getVersion(),
            'exclude'           => $this->isExcluded(),
            'maxDepth'          => $this->getMaxDepth(),
            'nameProperties'    => $this->getNameProperties(),
            'required'          => [],
            'properties'        => [],
        ];

        if ($objectEntity && $objectEntity->getEntity() !== $this) {
            throw new GatewayException('The given objectEntity has not have the same entity as this entity.');
        }

        // Set the schema type to an object.
        $schema['type'] = 'object';

        foreach ($this->getAttributes() as $attribute) {
            // Zetten van required.
            if ($attribute->getRequired()) {
                $schema['required'][] = $attribute->getName();
            }

            $property = [];

            // Aanmaken property.
            // @todo ik laad dit nu in als array maar eigenlijk wil je testen en alleen zetten als er waardes in zitten

            // Create an url to fetch the objects from the schema this property refers to.
            if ($attribute->getType() == 'object' && $attribute->getObject() !== null) {
                $property['_list'] = '/admin/objects?_self.schema.id='.$attribute->getObject()->getId()->toString();
            }

            if ($attribute->getType() === 'datetime' || $attribute->getType() === 'date') {
                $property['type'] = 'string';
                $property['format'] = $attribute->getType();
            } elseif ($attribute->getType()) {
                $property['type'] = $attribute->getType();
                $attribute->getFormat() && $property['format'] = $attribute->getFormat();
            }

            $stringReplace = str_replace('“', "'", $attribute->getDescription());
            $decodedDescription = str_replace('”', "'", $stringReplace);

            $attribute->getDescription() && $property['description'] = $decodedDescription;
            $attribute->getExample() && $property['example'] = $attribute->getExample();

            // What if we have an $object entity.
            if ($objectEntity instanceof ObjectEntity === true) {
                if ($attribute->getType() != 'object') {
                    $property['value'] = $objectEntity->getValue($attribute);
                } elseif ($attribute->getMultiple()) {
                    foreach ($objectEntity->getValueObject($attribute)->getObjects() as $object) {
                        $property['value'][] = $object->getId()->toString();
                    }
                } else {
                    $property['value'] = $objectEntity->getValueObject($attribute)->getStringValue();
                }
            }

            // What if the attribute is hooked to an object.
            if ($attribute->getType() === 'object' && $attribute->getObject() === true) {
                $property['$ref'] = '#/components/schemas/'.$attribute->getObject()->getName();
            }

            // Zetten van de property.
            $schema['properties'][$attribute->getName()] = $property;

            // Add the validators.
            foreach ($attribute->getValidations() as $validator => $validation) {
                if (!array_key_exists($validator, Entity::SUPPORTED_VALIDATORS) && $validation != null) {
                    if ($validator === 'required') {
                        continue;
                    }
                    $schema['properties'][$attribute->getName()][$validator] = $validation;
                }
            }
        }

        if (empty($schema['required']) === true) {
            unset($schema['required']);
        }

        return $schema;
    }

    public function getNameProperties(): ?array
    {
        return $this->nameProperties;
    }

    public function setNameProperties(?array $nameProperties): self
    {
        $this->nameProperties = $nameProperties;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function setMaxDepth(int $maxDepth): self
    {
        $this->maxDepth = $maxDepth;

        return $this;
    }

    /**
     * @return Collection|Endpoint[]
     */
    public function getEndpoints(): Collection
    {
        return $this->endpoints;
    }

    public function addEndpoint(Endpoint $endpoint): self
    {
        if (!$this->endpoints->contains($endpoint)) {
            $this->endpoints[] = $endpoint;
            $endpoint->addEntity($this);
        }

        return $this;
    }

    public function removeEndpoint(Endpoint $endpoint): self
    {
        if ($this->endpoints->removeElement($endpoint)) {
            $endpoint->removeEntity($this);
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function isExcluded(): bool
    {
        return $this->exclude;
    }

    /**
     * @param bool $exclude
     *
     * @return Entity
     */
    public function setExclude(bool $exclude): self
    {
        $this->exclude = $exclude;

        return $this;
    }

    /**
     * @return bool
     */
    public function getPersist(): bool
    {
        return $this->persist;
    }

    /**
     * @param bool $persist
     *
     * @return Entity
     */
    public function setPersist(bool $persist): self
    {
        $this->persist = $persist;

        return $this;
    }

    /**
     * @return bool
     */
    public function getCreateAuditTrails(): bool
    {
        return $this->createAuditTrails;
    }

    /**
     * @param bool $createAuditTrails
     *
     * @return Entity
     */
    public function setCreateAuditTrails(bool $createAuditTrails): self
    {
        $this->createAuditTrails = $createAuditTrails;

        return $this;
    }

    public function getDefaultSource(): ?Source
    {
        return $this->defaultSource;
    }

    public function setDefaultSource(?Source $defaultSource): self
    {
        $this->defaultSource = $defaultSource;

        return $this;
    }
}
