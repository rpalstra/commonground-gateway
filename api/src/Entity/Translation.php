<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\TranslationRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * This entity holds the information about an Endpoint.
 *
 * @ApiResource(
 *     	normalizationContext={"groups"={"read"}, "enable_max_depth"=true},
 *     	denormalizationContext={"groups"={"write"}, "enable_max_depth"=true},
 *  itemOperations={
 *      "get"={"path"="/admin/translations/{id}"},
 *      "put"={"path"="/admin/translations/{id}"},
 *      "delete"={"path"="/admin/translations/{id}"}
 *  },
 *  collectionOperations={
 *      "get"={"path"="/admin/translations"},
 *      "post"={"path"="/admin/translations"}
 *  })
 * )
 * @ORM\Entity(repositoryClass=TranslationRepository::class)
 * @Gedmo\Loggable(logEntryClass="Conduction\CommonGroundBundle\Entity\ChangeLog")
 */
class Translation
{
    /**
     * @var UuidInterface The UUID identifier of this Entity.
     *
     * @example e2984465-190a-4562-829e-a8cca81aa35d
     *
     * @Assert\Uuid
     * @Groups({"read","read_secure"})
     * @ORM\Id
     * @ORM\Column(type="uuid", unique=true)
     * @ORM\GeneratedValue(strategy="CUSTOM")
     * @ORM\CustomIdGenerator(class="Ramsey\Uuid\Doctrine\UuidGenerator")
     */
    private $id;

    /**
     * @var string The table of this Translation.
     *
     * @Assert\NotNull
     * @Assert\Length(
     *     max = 255
     * )
     * @Groups({"read","write"})
     * @ORM\Column(type="string", length=255)
     */
    private $translationTable;

    /**
     * @var string Translate from of this Translation.
     *
     * @Assert\NotNull
     * @Assert\Length(
     *     max = 255
     * )
     * @Groups({"read","write"})
     * @ORM\Column(type="string", length=255)
     */
    private $translateFrom;

    /**
     * @var string Translate to of this Translation.
     *
     * @Assert\NotNull
     * @Assert\Length(
     *     max = 255
     * )
     * @Groups({"read","write"})
     * @ORM\Column(type="string", length=255)
     */
    private $translateTo;

    /**
     * @var string The languages of this Handler.
     *
     * @Groups({"read", "write"})
     * @ORM\Column(type="string", nullable=true)
     */
    private $language;

    public function getId()
    {
        return $this->id;
    }

    public function getTranslationTable(): ?string
    {
        return $this->translationTable;
    }

    public function setTranslationTable(string $translationTable): self
    {
        $this->translationTable = $translationTable;

        return $this;
    }

    public function getTranslateFrom(): ?string
    {
        return $this->translateFrom;
    }

    public function setTranslateFrom(string $translateFrom): self
    {
        $this->translateFrom = $translateFrom;

        return $this;
    }

    public function getTranslateTo(): ?string
    {
        return $this->translateTo;
    }

    public function setTranslateTo(string $translateTo): self
    {
        $this->translateTo = $translateTo;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language;

        return $this;
    }
}
