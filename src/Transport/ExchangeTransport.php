<?php

namespace TahsinGokalp\LaravelEwsDriver\Transport;

use jamesiarmes\PhpEws\ArrayType\ArrayOfRecipientsType;
use jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfAllItemsType;
use jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfAttachmentsType;
use jamesiarmes\PhpEws\Client;
use jamesiarmes\PhpEws\Enumeration\BodyTypeType;
use jamesiarmes\PhpEws\Enumeration\DistinguishedPropertySetType;
use jamesiarmes\PhpEws\Enumeration\ImportanceChoicesType;
use jamesiarmes\PhpEws\Enumeration\MapiPropertyTypeType;
use jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use jamesiarmes\PhpEws\Request\CreateItemType;
use jamesiarmes\PhpEws\Type\BodyType;
use jamesiarmes\PhpEws\Type\EmailAddressType;
use jamesiarmes\PhpEws\Type\ExtendedPropertyType;
use jamesiarmes\PhpEws\Type\FileAttachmentType;
use jamesiarmes\PhpEws\Type\MessageType;
use jamesiarmes\PhpEws\Type\PathToExtendedFieldType;
use jamesiarmes\PhpEws\Type\SingleRecipientType;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException;
use Throwable;

class ExchangeTransport extends AbstractTransport
{
    /**
     * Headers that are mapped to EWS fields or set by Exchange itself, and
     * so are not forwarded as custom internet headers.
     */
    protected const MAPPED_HEADERS = [
        'from', 'sender', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date',
        'message-id', 'in-reply-to', 'references', 'return-path',
        'mime-version', 'content-type', 'content-transfer-encoding', 'content-disposition',
        'x-priority', 'disposition-notification-to', 'return-receipt-to',
    ];

    protected EwsDriverConfig $config;

    public function __construct(EwsDriverConfig $config)
    {
        parent::__construct();
        $this->config = $config;
    }

    /**
     * @throws EwsException
     */
    protected function doSend(SentMessage $message): void
    {
        $originalMessage = $message->getOriginalMessage();

        $email = MessageConverter::toEmail($originalMessage);

        $client = $this->createClient();

        $request = new CreateItemType();
        $request->Items = new NonEmptyArrayOfAllItemsType();

        $request->MessageDisposition = $this->config->messageDispositionType;

        // Create the ewsMessage.
        $ewsMessage = new MessageType();
        $ewsMessage->Subject = $email->getSubject();
        $ewsMessage->ToRecipients = new ArrayOfRecipientsType();

        // Set the sender, falling back to the configured address.
        if ($sender = $this->resolveSender($email)) {
            $ewsMessage->From = new SingleRecipientType();
            $ewsMessage->From->Mailbox = $sender;
        }

        // Set the recipient.
        foreach ($email->getTo() as $address) {
            $ewsMessage->ToRecipients->Mailbox[] = $this->addressToExchangeAddress($address);
        }

        // Set the CC
        foreach ($email->getCc() as $address) {
            $ewsMessage->CcRecipients ??= new ArrayOfRecipientsType();
            $ewsMessage->CcRecipients->Mailbox[] = $this->addressToExchangeAddress($address);
        }

        // Set the BCC
        foreach ($email->getBcc() as $address) {
            $ewsMessage->BccRecipients ??= new ArrayOfRecipientsType();
            $ewsMessage->BccRecipients->Mailbox[] = $this->addressToExchangeAddress($address);
        }

        // Set Reply-To, importance, receipts, threading and custom headers.
        $this->applyHeaders($email, $ewsMessage);

        $htmlBody = $this->bodyToString($email->getHtmlBody());
        $cidReplacements = [];

        // Add attachments, marking embedded images as inline.
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $name = $headers->getHeaderParameter('Content-Type', 'name');

            $fileAttachment = new FileAttachmentType();
            $fileAttachment->Content = $attachment->getBody();
            $fileAttachment->Name = $name;
            $fileAttachment->ContentType = $headers->getHeaderBody('Content-Type');

            if ($this->isInline($attachment, $name, $htmlBody)) {
                $contentId = $attachment->getContentId();
                $fileAttachment->IsInline = true;
                $fileAttachment->ContentId = $contentId;

                if ($name !== null && $name !== $contentId) {
                    $cidReplacements['cid:'.$name] = 'cid:'.$contentId;
                }
            }

            $ewsMessage->Attachments ??= new NonEmptyArrayOfAttachmentsType();
            $ewsMessage->Attachments->FileAttachment ??= [];
            $ewsMessage->Attachments->FileAttachment[] = $fileAttachment;
        }

        // Set the ewsMessage body.
        $ewsMessage->Body = new BodyType();

        if ($htmlBody !== null && $htmlBody !== '') {
            $ewsMessage->Body->BodyType = BodyTypeType::HTML;
            // Point cid: references made by file name at the attachment's Content-ID.
            $ewsMessage->Body->_ = strtr($htmlBody, $cidReplacements);
        } else {
            $ewsMessage->Body->BodyType = BodyTypeType::TEXT;
            $ewsMessage->Body->_ = $this->bodyToString($email->getTextBody());
        }

        $request->Items->Message[] = $ewsMessage;

        try {
            $response = $client->CreateItem($request);
        } catch (Throwable $e) {
            throw new EwsException('Exchange request failed: '.$e->getMessage(), (int) $e->getCode(), $e);
        }

        // Iterate over the results, printing any error messages or ewsMessage ids.
        $response_messages = $response->ResponseMessages->CreateItemResponseMessage;
        foreach ($response_messages as $response_message) {
            // Make sure the request succeeded.
            if ($response_message->ResponseClass !== ResponseClassType::SUCCESS) {
                $code = $response_message->ResponseCode;
                $ewsMessage = $response_message->MessageText;

                throw new EwsException("Message failed to create with \"$code: $ewsMessage\"\n");
            }
        }
    }

    /**
     * Create the EWS client used to send the message.
     *
     * @return Client
     */
    protected function createClient(): Client
    {
        $client = new Client(
            $this->config->host,
            $this->config->username,
            $this->config->password,
            $this->config->version
        );

        $client->setCurlOptions($this->config->verifySsl
            ? [CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]
            : [CURLOPT_SSL_VERIFYPEER => false]);

        return $client;
    }

    /**
     * Get the string representation of the transport.
     *
     * @return string
     */
    public function __toString(): string
    {
        return 'exchange';
    }

    /**
     * Map message headers that EWS has dedicated fields for, and forward the
     * remaining custom headers as internet header extended properties.
     *
     * @param Email       $email
     * @param MessageType $ewsMessage
     *
     * @return void
     */
    protected function applyHeaders(Email $email, MessageType $ewsMessage): void
    {
        foreach ($email->getReplyTo() as $address) {
            $ewsMessage->ReplyTo ??= new ArrayOfRecipientsType();
            $ewsMessage->ReplyTo->Mailbox[] = $this->addressToExchangeAddress($address);
        }

        $headers = $email->getHeaders();

        if ($headers->has('X-Priority')) {
            $ewsMessage->Importance = match (true) {
                $email->getPriority() < Email::PRIORITY_NORMAL => ImportanceChoicesType::HIGH,
                $email->getPriority() > Email::PRIORITY_NORMAL => ImportanceChoicesType::LOW,
                default => ImportanceChoicesType::NORMAL,
            };
        }

        if ($headers->has('Disposition-Notification-To')) {
            $ewsMessage->IsReadReceiptRequested = true;
        }

        if ($headers->has('Return-Receipt-To')) {
            $ewsMessage->IsDeliveryReceiptRequested = true;
        }

        if ($headers->has('In-Reply-To')) {
            $ewsMessage->InReplyTo = $headers->get('In-Reply-To')->getBodyAsString();
        }

        if ($headers->has('References')) {
            $ewsMessage->References = $headers->get('References')->getBodyAsString();
        }

        foreach ($headers->all() as $header) {
            if (in_array(strtolower($header->getName()), self::MAPPED_HEADERS, true)) {
                continue;
            }

            $property = new ExtendedPropertyType();
            $property->ExtendedFieldURI = new PathToExtendedFieldType();
            $property->ExtendedFieldURI->DistinguishedPropertySetId = DistinguishedPropertySetType::INTERNET_HEADERS;
            $property->ExtendedFieldURI->PropertyName = $header->getName();
            $property->ExtendedFieldURI->PropertyType = MapiPropertyTypeType::STRING;
            $property->Value = $header->getBodyAsString();

            $ewsMessage->ExtendedProperty[] = $property;
        }
    }

    /**
     * Determine whether an attachment is embedded in the HTML body.
     *
     * @param DataPart    $attachment
     * @param string|null $name
     * @param string|null $htmlBody
     *
     * @return bool
     */
    protected function isInline(DataPart $attachment, ?string $name, ?string $htmlBody): bool
    {
        if ($attachment->getPreparedHeaders()->getHeaderBody('Content-Disposition') === 'inline') {
            return true;
        }

        if ($htmlBody === null) {
            return false;
        }

        // Symfony treats parts referenced as cid:<name> or cid:<content-id> as inline.
        return ($name !== null && str_contains($htmlBody, 'cid:'.$name))
            || ($attachment->hasContentId() && str_contains($htmlBody, 'cid:'.$attachment->getContentId()));
    }

    /**
     * @param resource|string|null $body
     *
     * @return string|null
     */
    protected function bodyToString($body): ?string
    {
        if (is_resource($body)) {
            if (stream_get_meta_data($body)['seekable'] ?? false) {
                rewind($body);
            }

            return stream_get_contents($body);
        }

        return $body;
    }

    /**
     * Resolve the sender mailbox from the message, or the configured address.
     *
     * @param Email $email
     *
     * @return EmailAddressType|null
     */
    protected function resolveSender(Email $email): ?EmailAddressType
    {
        if ($from = $email->getFrom()) {
            return $this->addressToExchangeAddress($from[0]);
        }

        if ($this->config->from !== null) {
            return $this->addressToExchangeAddress(new Address($this->config->from));
        }

        return null;
    }

    /**
     * @param Address $address
     *
     * @return EmailAddressType
     */
    protected function addressToExchangeAddress(Address $address): EmailAddressType
    {
        $recipient = new EmailAddressType();
        $recipient->EmailAddress = $address->getAddress();
        if ($address->getName() !== '') {
            $recipient->Name = $address->getName();
        }

        return $recipient;
    }
}
