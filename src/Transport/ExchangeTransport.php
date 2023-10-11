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
use Symfony\Component\Mime\MessageConverter;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException;

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

        $client = new Client(
            $this->config->host,
            $this->config->username,
            $this->config->password,
            $this->config->version
        );

        $client->setCurlOptions(array(CURLOPT_SSL_VERIFYPEER => false));

        $request = new CreateItemType();
        $request->Items = new NonEmptyArrayOfAllItemsType();

        $request->MessageDisposition = $this->config->messageDispositionType;

        // Create the ewsMessage.
        $ewsMessage = new MessageType();
        $ewsMessage->Subject = $email->getSubject();
        $ewsMessage->ToRecipients = new ArrayOfRecipientsType();

        // Set the sender.
        $ewsMessage->From = new SingleRecipientType();
        $ewsMessage->From->Mailbox = new EmailAddressType();
        $ewsMessage->From->Mailbox->EmailAddress = $this->config->from;

        // Set the recipient.
        foreach ($originalMessage->getTo() as $address) {
            $ewsMessage->ToRecipients->Mailbox[] = $this->addressToExchangeAddress($address);
        }

        // Set the CC
        foreach ($originalMessage->getCc() as $address) {
            $ewsMessage->CcRecipients ??= new ArrayOfRecipientsType();
            $ewsMessage->CcRecipients->Mailbox[] = $this->addressToExchangeAddress($address);
        }

        // Set the BCC
        foreach ($originalMessage->getBcc() as $address) {
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
        $response = $client->CreateItem($request);

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
     * Get the string representation of the transport.
     *
     * @return string
     */
    public function __toString(): string
    {
        return 'exchange';
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
        if ($address->getName() !== null) {
            $recipient->Name = $address->getName();
        }

        return $recipient;
    }
}
