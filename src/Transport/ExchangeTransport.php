<?php

namespace TahsinGokalp\LaravelEwsDriver\Transport;

use jamesiarmes\PhpEws\ArrayType\ArrayOfRecipientsType;
use jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfAllItemsType;
use jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfAttachmentsType;
use jamesiarmes\PhpEws\Client;
use jamesiarmes\PhpEws\Enumeration\BodyTypeType;
use jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use jamesiarmes\PhpEws\Request\CreateItemType;
use jamesiarmes\PhpEws\Type\BodyType;
use jamesiarmes\PhpEws\Type\EmailAddressType;
use jamesiarmes\PhpEws\Type\FileAttachmentType;
use jamesiarmes\PhpEws\Type\MessageType;
use jamesiarmes\PhpEws\Type\SingleRecipientType;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException;
use Throwable;

class ExchangeTransport extends AbstractTransport
{
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

        // Set the ewsMessage body.
        $ewsMessage->Body = new BodyType();

        if ($htmlBody = $email->getHtmlBody()) {
            $ewsMessage->Body->BodyType = BodyTypeType::HTML;
            $ewsMessage->Body->_ = $htmlBody;
        } else {
            $ewsMessage->Body->BodyType = BodyTypeType::TEXT;
            $ewsMessage->Body->_ = $email->getTextBody();
        }

        // Add attachments
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $name = $headers->getHeaderParameter('content-type', 'name');
            $contentType = $headers->getHeaderBody('content-type');

            $fileAttachment = new FileAttachmentType();
            $fileAttachment->Content = $attachment->getBody();
            $fileAttachment->ContentId = $attachment->getContentId();
            $fileAttachment->Name = $name;
            $fileAttachment->ContentType = $contentType;

            $ewsMessage->Attachments ??= new NonEmptyArrayOfAttachmentsType();
            $ewsMessage->Attachments->FileAttachment ??= [];
            $ewsMessage->Attachments->FileAttachment[] = $fileAttachment;
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
