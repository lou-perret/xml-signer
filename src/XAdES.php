<?php

/**
 * Copyright (c) 2021 and later years, Bill Seddon <bill.seddon@lyquidity.com>.
 * All rights reserved.
 *
 * MIT License 
 * 
 * Allows a document to be signed using one of the XAdES forms up to XAdES-T.
 * https://www.w3.org/TR/XAdES/
 * 
 * Builds on XMLSecurityDSig to sign and verify Xml documents using the XAdES 
 * extensions which take it into the domain of non-repudiation by defining XML 
 * formats for advanced electronic signatures that remain valid over long periods 
 * and are compliant with the European "Directive 1999/93/EC.
 * 
 * The XAdES support distinguishes two types of signing action:
 * 	1) The creation of an initial signature complete will policy and commitment details
 * 	2) Updates to add features like a timestamp and counter signatures.
 * 
 * If a document is signed second and subsequent times and a set XAdES signed properties
 * already exist then an additional individual signature covering the existing properties.
 * 
 * If the action is to update an existing signature then second and subsequent signatures
 * will be counter-signatures. In this case the signer is able to select the unique id
 * of the signature their counter-signature will cover.
 * 
 * The caller has two ways to create the properties to be signed in the first place.
 * The first is to call a set of functions.  The other is similar in style to xadesjs
 * where a nested array of properties can be passed to the sign function and these
 * properties will drive the process of creating the XAdES <SignedProperties> and
 * XmlDSig <Signature> elements.
 */

namespace lyquidity\xmldsig;

use lyquidity\Asn1\Der\Decoder;
use lyquidity\Asn1\Element\Sequence;
use lyquidity\Asn1\UniversalTagID;
use lyquidity\Asn1\Util\BigInteger;
use lyquidity\OCSP\CertificateInfo;
use lyquidity\OCSP\CertificateLoader;
use lyquidity\OCSP\Exception\ResponseException\MissingResponseBytesException;
use lyquidity\OCSP\Ocsp;
use lyquidity\TSA\TSA;
use lyquidity\xmldsig\xml\Cert;
use lyquidity\xmldsig\xml\CertV2;
use lyquidity\xmldsig\xml\CounterSignature;
use lyquidity\xmldsig\xml\DataObjectFormat;
use lyquidity\xmldsig\xml\DigestMethod;
use lyquidity\xmldsig\xml\DigestValue;
use lyquidity\xmldsig\xml\ElementNames;
use lyquidity\xmldsig\xml\Generic;
use lyquidity\xmldsig\xml\Obj;
use lyquidity\xmldsig\xml\QualifyingProperties;
use lyquidity\xmldsig\xml\Signature;
use lyquidity\xmldsig\xml\SignaturePolicyId;
use lyquidity\xmldsig\xml\SignaturePolicyIdentifier;
use lyquidity\xmldsig\xml\SignatureProductionPlace;
use lyquidity\xmldsig\xml\SignatureProductionPlaceV2;
use lyquidity\xmldsig\xml\SignatureTimeStamp;
use lyquidity\xmldsig\xml\SignatureValue;
use lyquidity\xmldsig\xml\SignedDataObjectProperties;
use lyquidity\xmldsig\xml\SignedProperties;
use lyquidity\xmldsig\xml\SignedSignatureProperties;
use lyquidity\xmldsig\xml\SignerRole;
use lyquidity\xmldsig\xml\SignerRoleV2;
use lyquidity\xmldsig\xml\SigningCertificateV2;
use lyquidity\xmldsig\xml\SigningTime;
use lyquidity\xmldsig\xml\UnsignedProperties;
use lyquidity\xmldsig\xml\UnsignedSignatureProperties;
use lyquidity\xmldsig\xml\X509SerialNumber;
use lyquidity\xmldsig\xml\XmlCore;
use function lyquidity\Asn1\asSequence;

/**
 */
class XAdES extends XMLSecurityDSig
{
	/**
	 * Namespace defined in ETSI 319 132-1 V1.1.0 (2016-02)
	 */
	const NamespaceUrl2016 = "http://uri.etsi.org/01903/v1.3.2#";
	const NamespaceUrl2003 = "http://uri.etsi.org/01903/v1.1.1#";

	// XAdES allows for counter signature in which case this url should be included as the &lt;Reference> @Type
	const counterSignatureTypeUrl = "http://uri.etsi.org/01903#CountersignedSignature";

	// Xades specification requires "http://uri.etsi.org/01903/v1.1.1#SignedProperties" as the &lt;Reference> @Type
	const ReferenceType = "http://uri.etsi.org/01903#SignedProperties";

	// This transform indicates that the hash value of the signature policy document has been computed as specified in a certain technical specification
	const PolicyTranform = "http://uri.etsi.org/01903/v1.3.2/SignaturePolicy/SPDocDigestAsInSpecification";

	const SignedPropertiesId = "signed-properties";
	const UnsignedPropertiesId = "unsigned-properties";
	const SignatureRootId = "signature-root";
	const SignatureFilename = "signature.xml";

	// All the XPath queries assume ds=XMLSecurityDSig::XMLDSIGNS and xa=self::NamespaceUrl
	const unsignedPropertiesQuery = "/ds:Signature/ds:Object/xa:QualifyingProperties/xa:unsignedProperties[@Id=\"" . self::UnsignedPropertiesId . "\"]";

	/**
	 * Defines the xades namespace to use
	 * @var string
	 */
	private $currentNamespace = self::NamespaceUrl2016;

	/**
	 * The reference to the name of the file containing the Xml to be signed.
	 * @var ResourceInfo
	 */
	protected $fileBeingSigned = null;

	/**
	 * Create a signature for a resource
	 * 
	 * This is a convenience function.  More control over the signature creation can be achieved by creating the XAdES instance directly
	 *
	 * @param InputResourceInfo|string $xmlResource
	 * @param CertificateResourceInfo|string $certificateResource
	 * @param KeyResourceInfo|string $keyResource
	 * @param SignatureProductionPlace|SignatureProductionPlaceV2 $signatureProductionPlace
	 * @param SignerRole|SignerRoleV2 $signerRole
	 * @param string $canonicalizationMethod (optional) This will use C14N by default
	 * @param bool $addTimestamp (optional)
	 * @return XAdES
	 */
	public static function signDocument( $xmlResource, $certificateResource, $keyResource = null, $signatureProductionPlace = null, $signerRole = null, $canonicalizationMethod = self::C14N, $addTimestamp = false )
	{
		$instance = new static( XMLSecurityDSig::defaultPrefix, $xmlResource->signatureId );
		$instance->signXAdESFile( $xmlResource, $certificateResource, $keyResource, $signatureProductionPlace, $signerRole, $canonicalizationMethod, $addTimestamp );
		return $instance;
	}

	/**
	 * Create a signature for a resource but without the SignatureValue element
	 * The canonicalized SignedInfo will be returned as a string for signing by
	 * a third party.
	 * 
	 * This is a convenience function.  More control over the signature creation can be achieved by creating the XAdES instance directly
	 *
	 * @param InputResourceInfo|string $xmlResource
	 * @param CertificateResourceInfo|string $certificateResource
	 * @param SignatureProductionPlace|SignatureProductionPlaceV2 $signatureProductionPlace
	 * @param SignerRole|SignerRoleV2 $signerRole
	 * @param string $canonicalizationMethod (optional) This will use C14N by default
	 * @param bool $addTimestamp (optional) A timestamp is not added by default
	 * @return string
	 */
	public static function getCanonicalizedSI( $xmlResource, $certificateResource, $signatureProductionPlace = null, $signerRole = null, $canonicalizationMethod = self::C14N, $addTimestamp = false )
	{
		$instance = new static( XMLSecurityDSig::defaultPrefix, $xmlResource->signatureId );
		return $instance->getCanonicalizedSignedInfo( $xmlResource, $certificateResource, $signatureProductionPlace, $signerRole, $canonicalizationMethod, $addTimestamp );
	}

	/**
	 * Extends the core XmlDSig verification to also verify &lt;Object>/&lt;QualifyingProperties>/&lt;SignedProperties>
	 * 
	 * This is a convenience function.  More control over the signature creation can be achieved by creating the XAdES instance directly
	 *
	 * @param string $signatureFile This might be a standalone signature file
	 * @param string $certificateFile (optional) If provided it is an absolute path to the relevant .crt file or a path relative to the signature file
	 * @return XAdES
	 */
	public static function verifyDocument( $signatureFile, $certificateFile = null )
	{
		$instance = new static( XMLSecurityDSig::defaultPrefix, XAdES::SignatureRootId );
		$instance->verifyXAdES( $signatureFile, $certificateFile );
		return $instance;
	}

	/**
	 * Add a counter signature to an exising signature
	 *
	 * This is a convenience function.  More control over the signature creation can be achieved by creating the XAdES instance directly
	 * 
	 * @param SignedDocumentResourceInfo $xmlResource
	 * @param CertificateResourceInfo $certificateResource
	 * @param KeyResourceInfo $keyResource
	 * @param SignatureProductionPlace|SignatureProductionPlaceV2 $signatureProductionPlace
	 * @param SignerRole|SignerRoleV2 $signerRole
	 * @param bool $canonicalizeOnly (optional: default = false) True when the canonicalized SI should be returned and the signature not signed
	 * @return XAdES|string[]|bool The instance will be returned.
	 */
	public static function counterSign( $xmlResource, $certificateResource, $keyResource = null, $signatureProductionPlace = null, $signerRole = null, &$canonicalizeOnly = true )
	{
		$instance = new static();
		return $instance->addCounterSignature( $xmlResource, $certificateResource, $keyResource, $signatureProductionPlace, $signerRole, $canonicalizeOnly );
	}

	/**
	 * Create a signature for a resource
	 *
	 * @param InputResourceInfo|string $xmlResource
	 * @param CertificateResourceInfo|string $certificateResource
	 * @param KeyResourceInfo|string $keyResource
	 * @param SignatureProductionPlace|SignatureProductionPlaceV2 $signatureProductionPlace
	 * @param SignerRole|SignerRoleV2 $signerRole
	 * @param string $canonicalizationMethod (optional) This will use C14N by default
	 * @param bool $addTimestamp (optional)
	 * @return bool
	 */
	function signXAdESFile( $xmlResource, $certificateResource, $keyResource = null, $signatureProductionPlace = null, $signerRole = null, $canonicalizationMethod = self::C14N, $addTimestamp = false )
	{
		if ( is_string( $xmlResource ) )
		{
			// If a simple string is passed in, assume it is a file name
			// Any problems with this assumption will appear later
			$xmlResource = new InputResourceInfo( $xmlResource, ResourceInfo::file );
		}
		else
		{
			// Make sure the argument is the correct type
			if ( ! $xmlResource instanceof InputResourceInfo )
				throw new XAdESException("The input resource must be a path to an XML file or an InputResourceInfo instance");
		}

		if ( is_string( $certificateResource ) )
		{
			// If a simple string is passed in, assume it is a file name
			// Any problems with this assumption will appear later
			$certificateResource = new CertificateResourceInfo( $certificateResource, ResourceInfo::file );
		}
		else
		{
			// Make sure the certificate argument is the correct type
			if ( ! $certificateResource instanceof CertificateResourceInfo )
				throw new XAdESException("The certificate resource must be a CertificateResourceInfo instance");
		}

		if ( is_string( $keyResource ) )
		{
			// If a simple string is passed in, assume it is a file name
			// Any problems with this assumption will appear later
			$keyResource = new KeyResourceInfo( $keyResource, ResourceInfo::file );
		}
		else
		{
			// Make sure the key argument is the correct type
			if ( ! $keyResource instanceof KeyResourceInfo )
				throw new XAdESException("The key resource must be a KeyResourceInfo instance");
		}

		if ( $xmlResource->isFile() )
		{
			if ( ! file_exists( $xmlResource->resource ) )
			{
				throw new XAdESException( "XML file does not exist" );
			}
	
			// Load the XML to be signed
			$doc = new \DOMDocument();
			$doc->load( $xmlResource->resource );
		}
		else if ( $xmlResource->isXmlDocument() )
		{
			$doc = $xmlResource->resource;
		}
		else if ( $xmlResource->isString() || $xmlResource->isURL() )
		{
			// Load the XML to be signed
			$doc = new \DOMDocument();
			$doc->load( $xmlResource->resource );
		}
		else
		{
			throw new XAdESException( "The resource supplied representing the document to be signed is not valid." );
		}

		if ( ! $xmlResource->detached )
		if ( $xmlResource->isXmlDocument() || $xmlResource->isString() || $xmlResource->isURL() )
		{
			// When the source is a string or url or a DOM document and the signature is not 
			// detatched then there must be a location and file name defined
			if ( ! $xmlResource->saveLocation || ! $xmlResource->saveFilename )
			{
				throw new XAdESException("If the input XML document is provided as a string, a DOM node or a URL then a save location and a save file name must be provided.");
			}
		}

		$this->fileBeingSigned = $xmlResource;

		$xpath = new \DOMXPath( $doc );
		$xpath->registerNamespace( 'ds', XMLSecurityDSig::XMLDSIGNS );
		$xpath->registerNamespace( 'xa', $this->currentNamespace );
		$hasSignature = $xpath->query( '//ds:Signature' )->count() > 0;
		if ( ! $xmlResource->detached && $hasSignature )
		{
			throw new XAdESException("The input document already contains a signature.  If you want additional indpendent signatures create a detatched signature instead.");
		}
		unset( $xpath );
		
		$this->setCanonicalMethod( $canonicalizationMethod ? $canonicalizationMethod : self::C14N );

		// Create a reference id to use 
		// $referenceId = 'xmldsig-ref0'; // XMLSecurityDSig::generateGUID('xades-');
		$referenceId = XMLSecurityDSig::generateGUID('xmldsig-');

		// Create a Qualifying properties hierarchy
		$signaturePropertiesId = null;
		$qualifyingProperties = $this->createQualifyingProperties( 
			$this->signatureId, 
			$certificateResource->isFile() ? file_get_contents( $certificateResource->resource ) : $certificateResource->resource, 
			$signatureProductionPlace, 
			$signerRole, 
			$signaturePropertiesId,
			$referenceId
		);

		// If the signature is to be attached, add a prefix so when the signature 
		// is attached the importNode function does not add a 'default' prefix.
		if ( ! $xmlResource->detached )
			$qualifyingProperties->traverse( function( XmlCore $node )
			{
				if ( $node->defaultNamespace && $node->defaultNamespace != $this->currentNamespace ) 
					return;
				$node->prefix = 'xa';
			} );

		// Add the Xml to the signature
		$object = $this->addObject( null );
		$qualifyingProperties->generateXml( $object );

		// Get the specific node to be included in the signature
        $xpath = $this->getXPathObj();
        $xpath->registerNamespace( 'xa', $this->currentNamespace );
        $nodes = $xpath->query("./xa:QualifyingProperties/xa:SignedProperties[\"@Id={$this->signatureId}\"]", $object );
        if ( ! $nodes->length )
			throw new XAdESException();
		unset( $object );

		$this->addReference(
			$nodes[0],
			XMLSecurityDSig::SHA256, 
			array( // Transforms
				$canonicalizationMethod
			),
			array( // Options
				'overwrite' => false,
				'type' => self::ReferenceType
			)
		);

		// Sign using SHA-256
		$this->addReference(
			$doc, // Content
			XMLSecurityDSig::SHA256, // Algorithm
			$xmlResource->convertTransforms( ! $xmlResource->detached ), // Transforms
			array( // Options
				'force_uri' => $xmlResource->detached
					? ( $xmlResource->isURL() 
						? XMLSecurityDSig::encodedUrl( parse_url( $xmlResource->resource ) ) 
						: basename( $xmlResource->resource )
					  )
					: true,
				'id' => $referenceId,
			)
		);

		// Create a new (private) Security key
		$objKey = new XMLSecurityKey( XMLSecurityKey::RSA_SHA256, array( 'type'=>'private' ) );

		if ( $keyResource->isFile() )
		{
			if ( ! file_exists( $keyResource->resource ) )
			{
				throw new XAdESException( "Key file does not exist" );
			}

			// Load the signing key
			$objKey->loadKey( $keyResource->resource, true );
		}
		else if ( $keyResource->isString() | $keyResource->isDER() )
		{
			// Load the signing key
			$objKey->loadKey( $keyResource->resource, false );
		}
		else
		{
			throw new XAdESException( "The resource supplied representing the certificate to be recorded in the signature is not valid." );
		}

		/*
		If key has a passphrase, set it using
		$objKey->passphrase = '<passphrase>';
		*/

		// Sign the XML file
		$this->sign( $objKey );
	
		// Add the associated public key to the signature
		if ( $certificateResource->isFile() )
		{
			if ( ! file_exists( $certificateResource->resource ) )
			{
				throw new XAdESException( "Certificate file does not exist" );
			}

			// Add the associated public key to the signature
			$certificate = file_get_contents( $certificateResource->resource );
			$this->add509Cert( $certificate );
		}
		else if ( $certificateResource->isString() | $certificateResource->isDER() )
		{
			// Add the associated public key to the signature
			$certificate = $certificateResource->resource;
			$this->add509Cert( $certificateResource->resource, $certificateResource->isPEM(), $certificateResource->isURL() );
		}
		else
		{
			throw new XAdESException( "The resource supplied representing the certificate to be recorded in the signature is not valid." );
		}

		/**
		 * Timestamp
		 */
		if ( $addTimestamp )
		{
			// Just using the xpath object to get the signature document
			$xpath = $this->getXPathObj();
			$this->addTimestamp( $xpath->document, self::SignatureRootId );
		}

		$location = $xmlResource->saveLocation
			? "{$xmlResource->saveLocation}"
			: (
				$xmlResource->isFile()
					? dirname( $xmlResource->resource )
					: __DIR__
			  );

		$filename = $xmlResource->saveFilename
		? "{$xmlResource->saveFilename}"
		: (
			$xmlResource->isFile()
				? basename( $xmlResource->resource )
				: self::SignatureFilename
		);

		// Add 'xml' extension if one ios not provided
		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) )
			$filename .= '.xml';

		if ( $xmlResource->detached )
		{
	  		$this->sigNode->ownerDocument->save( $this->getSignatureFilename( $location, $filename ), LIBXML_NOEMPTYTAG );
		}
		else
		{
			/** @var \DOMElement $signature */
			$signature = $this->appendSignature( $doc->documentElement );
			// DOMElement::importNode screws around with namespaces. In this case it will
			// add the XAdES namespace to the <Signature> node.  This needs to be removed.
			// $result = $signature->removeAttributeNS( $this->currentNamespace, 'xa' );
			$xml = preg_replace('!\s*?xmlns:xa="http://uri.etsi.org/01903/v1.3.2#"!', '', $doc->saveXML( null, LIBXML_NOEMPTYTAG ), 1 );

			// $doc->save( $this->getSignatureFilename( $location, $filename ), LIBXML_NOEMPTYTAG );	
			file_put_contents( $this->getSignatureFilename( $location, $filename ), $xml );
		}

		return true;
	}

	/**
	 * Create a signature for a resource
	 *
	 * @param InputResourceInfo|string $xmlResource
	 * @param CertificateResourceInfo|string $certificateResource
	 * @param SignatureProductionPlace|SignatureProductionPlaceV2 $signatureProductionPlace
	 * @param SignerRole|SignerRoleV2 $signerRole
	 * @param string $canonicalizationMethod (optional) This will use C14N by default
	 * @param bool $addTimestamp (optional)
	 * @return bool
	 */
	protected function getCanonicalizedSignedInfo( $xmlResource, $certificateResource, $signatureProductionPlace = null, $signerRole = null, $canonicalizationMethod = self::C14N, $addTimestamp = false )
	{
		if ( is_string( $xmlResource ) )
		{
			// If a simple string is passed in, assume it is a file name
			// Any problems with this assumption will appear later
			$xmlResource = new InputResourceInfo( $xmlResource, ResourceInfo::file );
		}
		else
		{
			// Make sure the argument is the correct type
			if ( ! $xmlResource instanceof InputResourceInfo )
				throw new XAdESException("The input resource must be a path to an XML file or an InputResourceInfo instance");
		}

		if ( is_string( $certificateResource ) )
		{
			// If a simple string is passed in, assume it is a file name
			// Any problems with this assumption will appear later
			$certificateResource = new CertificateResourceInfo( $certificateResource, ResourceInfo::file );
		}
		else
		{
			// Make sure the certificate argument is the correct type
			if ( ! $certificateResource instanceof CertificateResourceInfo )
				throw new XAdESException("The certificate resource must be a CertificateResourceInfo instance");
		}

		if ( $xmlResource->isFile() )
		{
			if ( ! file_exists( $xmlResource->resource ) )
			{
				throw new XAdESException( "XML file does not exist" );
			}
	
			// Load the XML to be signed
			$doc = new \DOMDocument();
			$doc->load( $xmlResource->resource );
		}
		else if ( $xmlResource->isXmlDocument() )
		{
			$doc = $xmlResource->resource;
		}
		else if ( $xmlResource->isString() || $xmlResource->isURL() )
		{
			// Load the XML to be signed
			$doc = new \DOMDocument();
			$doc->load( $xmlResource->resource );
		}
		else
		{
			throw new XAdESException( "The resource supplied representing the document to be signed is not valid." );
		}

		if ( ! $xmlResource->detached )
		if ( $xmlResource->isXmlDocument() || $xmlResource->isString() || $xmlResource->isURL() )
		{
			// When the source is a string or url or a DOM document and the signature is not 
			// detatched then there must be a location and file name defined
			if ( ! $xmlResource->saveLocation || ! $xmlResource->saveFilename )
			{
				throw new XAdESException("If the input XML document is provided as a string, a DOM node or a URL then a save location and a save file name must be provided.");
			}
		}

		$this->fileBeingSigned = $xmlResource;

		$xpath = new \DOMXPath( $doc );
		$xpath->registerNamespace( 'ds', XMLSecurityDSig::XMLDSIGNS );
		$xpath->registerNamespace( 'xa', $this->currentNamespace );
		$hasSignature = $xpath->query( '//ds:Signature' )->count() > 0;
		if ( ! $xmlResource->detached && $hasSignature )
		{
			throw new XAdESException("The input document already contains a signature.  If you want additional indpendent signatures create a detatched signature instead.");
		}
		unset( $xpath );
		
		$this->setCanonicalMethod( $canonicalizationMethod ? $canonicalizationMethod : self::C14N );

		// Create a reference id to use 
		$referenceId = 'xmldsig-ref0'; // XMLSecurityDSig::generateGUID('xades-');

		// Create a Qualifying properties hierarchy
		$signaturePropertiesId = null;
		$qualifyingProperties = $this->createQualifyingProperties( 
			$this->signatureId, 
			$certificateResource->isFile() ? file_get_contents( $certificateResource->resource ) : $certificateResource->resource, 
			$signatureProductionPlace, 
			$signerRole, 
			$signaturePropertiesId,
			$referenceId
		);

		// If the signature is to be attached, add a prefix so when the signature 
		// is attached the importNode function does not add a 'default' prefix.
		if ( ! $xmlResource->detached )
			$qualifyingProperties->traverse( function( XmlCore $node )
			{
				if ( $node->defaultNamespace && $node->defaultNamespace != $this->currentNamespace ) 
					return;
				$node->prefix = 'xa';
			} );

		// Add the Xml to the signature
		$object = $this->addObject( null );
		$qualifyingProperties->generateXml( $object );

		// Get the specific node to be included in the signature
        $xpath = $this->getXPathObj();
        $xpath->registerNamespace( 'xa', $this->currentNamespace );
        $nodes = $xpath->query("./xa:QualifyingProperties/xa:SignedProperties[\"@Id={$this->signatureId}\"]", $object );
        if ( ! $nodes->length )
			throw new XAdESException();
		unset( $object );

		$this->addReference(
			$nodes[0],
			XMLSecurityDSig::SHA256, 
			array( // Transforms
				$canonicalizationMethod
			),
			array( // Options
				'overwrite' => false,
				'type' => self::ReferenceType
			)
		);

		// Sign using SHA-256
		$this->addReference(
			$doc, // Content
			XMLSecurityDSig::SHA256, // Algorithm
			$xmlResource->convertTransforms( ! $xmlResource->detached ), // Transforms
			array( // Options
				'force_uri' => $xmlResource->detached
					? ( $xmlResource->isURL() 
						? XMLSecurityDSig::encodedUrl( parse_url( $xmlResource->resource ) ) 
						: basename( $xmlResource->resource )
					  )
					: true,
				'id' => $referenceId,
			)
		);

		$canonicalizedSignedInfo = $this->getSignedInfoCanonicalized( XMLSecurityKey::RSA_SHA256 );
	
		// Add the associated public key to the signature
		if ( $certificateResource->isFile() )
		{
			if ( ! file_exists( $certificateResource->resource ) )
			{
				throw new XAdESException( "Certificate file does not exist" );
			}

			// Add the associated public key to the signature
			$certificate = file_get_contents( $certificateResource->resource );
			$this->add509Cert( $certificate );
		}
		else if ( $certificateResource->isString() | $certificateResource->isDER() )
		{
			// Add the associated public key to the signature
			$certificate = $certificateResource->resource;
			$this->add509Cert( $certificateResource->resource, $certificateResource->isPEM(), $certificateResource->isURL() );
		}
		else
		{
			throw new XAdESException( "The resource supplied representing the certificate to be recorded in the signature is not valid." );
		}

		/**
		 * Timestamp
		 */
		if ( $addTimestamp )
		{
			// Just using the xpath object to get the signature document
			$xpath = $this->getXPathObj();
			$this->addTimestamp( $xpath->document, self::SignatureRootId );
		}

		$location = $xmlResource->saveLocation
			? "{$xmlResource->saveLocation}"
			: (
				$xmlResource->isFile()
					? dirname( $xmlResource->resource )
					: __DIR__
			  );

		$filename = $xmlResource->saveFilename
		? "{$xmlResource->saveFilename}"
		: (
			$xmlResource->isFile()
				? basename( $xmlResource->resource )
				: self::SignatureFilename
		);

		// Add 'xml' extension if one ios not provided
		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) )
			$filename .= '.xml';

		if ( $xmlResource->detached )
		{
	  		$this->sigNode->ownerDocument->save( $this->getSignatureFilename( $location, $filename ), LIBXML_NOEMPTYTAG );
		}
		else
		{
			/** @var \DOMElement $signature */
			$signature = $this->appendSignature( $doc->documentElement );
			// DOMElement::importNode screws around with namespaces. In this case it will
			// add the XAdES namespace to the <Signature> node.  This needs to be removed.
			// $result = $signature->removeAttributeNS( $this->currentNamespace, 'xa' );
			$xml = preg_replace('!\s*?xmlns:xa="http://uri.etsi.org/01903/v1.3.2#"!', '', $doc->saveXML( null, LIBXML_NOEMPTYTAG ), 1 );

			// $doc->save( $this->getSignatureFilename( $location, $filename ), LIBXML_NOEMPTYTAG );	
			file_put_contents( $this->getSignatureFilename( $location, $filename ), $xml );
		}

		return $canonicalizedSignedInfo;
	}

	/**
	 * Get the filename to use to save the signature
	 * This can be overridden by desendent to specify a jurisdiction specific name
	 *
	 * @param string $location
	 * @param string $signatureName
	 * @return string
	 */
	protected function getSignatureFilename( $location, $signatureName = self::SignatureFilename )
	{
		return "$location/$signatureName";
	}

	/**
	 * Overridden in a descendent instance to provide a jurisdiction specific identifier
	 * @return SignaturePolicyIdentifier
	 */
	protected function getSignaturePolicyIdentifier()
	{
		return null;
	}

	/**
	 * Overridden in a descendent instance to provide a jurisdiction specific data
	 * @param string $referenceId The id that will be added to the signed info reference
	 * @return SignedDataObjectProperties
	 */
	protected function getSignedDataObjectProperties( $referenceId = null )
	{
		$sdop = new SignedDataObjectProperties(
			new DataObjectFormat(
				$this->fileBeingSigned->isFile() ? basename( $this->fileBeingSigned->resource ) : $this->fileBeingSigned->resource, // File reference
				null, // ObjectIdentifier
				'text/xml', // MimeType
				null, // Encoding
				"#$referenceId"
			),
			null, // CommitmentTypeIndication
			null, // AllDataObjectsTimeStamp
			null, // IndividualDataObjectsTimeStamp
			null
		);

		return $sdop;
	}

	/**
	 * Creates the &lt;QualifyingProperties> to be added to the &lt;Object>
	 * If you have a need to produce a custom &lt;QualifyingProperties> then
	 * override this method in a descendent class.
	 *
	 * @param string $signatureId
	 * @param string $certificate
	 * @param SignatureProductionPlace|SignatureProductionPlaceV2 $signatureProductionPlace
	 * @param SignerRole|SignerRoleV2 $signerRole
	 * @param string $signaturePropertiesId
	 * @param string $referenceId The id that will be added to the signed info reference.  Used as @Target on &lt;QualifyingProperties>
	 * @param string $signedPropertiesId The @Id to be assined to the &lt;SignedProperties>
	 * @return QualifyingProperties
	 */
	protected function createQualifyingProperties(
		$signatureId,
		$certificate = null,
		$signatureProductionPlace = null, 
		$signerRole = null,
		$signaturePropertiesId = null,
		$referenceId = null,
		$signedPropertiesId = self::SignedPropertiesId
	)
	{
		$loader = new CertificateLoader();
		$certs = CertificateLoader::getCertificates( $certificate );
		$cert = null;
		$issuer = null;
		if ( $certs )
		{
			$cert = $loader->fromString( reset( $certs ) );
			if ( next( $certs ) )
				$issuer = $loader->fromString( current( $certs ) );
		}
		else
		{
			$cert = $loader->fromFile( $certificate );
		}

		$signingCertificate = SigningCertificateV2::fromCertificate( $cert, $issuer );

		$qualifyingProperties = new QualifyingProperties(
			new SignedProperties(
				new SignedSignatureProperties(
					new SigningTime(),
					null, // signingCertificate
					$signingCertificate,
					$this->getSignaturePolicyIdentifier(),
					$signatureProductionPlace instanceof SignatureProductionPlace ? $signatureProductionPlace : null,
					$signatureProductionPlace instanceof SignatureProductionPlaceV2 ? $signatureProductionPlace : null,
					$signerRole instanceof SignerRole ? $signerRole : null,
					$signerRole instanceof SignerRoleV2 ? $signerRole : null,
					$signaturePropertiesId
				),
				$this->getSignedDataObjectProperties( $referenceId ),
				$signedPropertiesId
			),
			null,
			$signatureId
		);

		return $qualifyingProperties;
	}

	/**
	 * Extends the core XmlDSig verification to also verify <Object/QualifyingProperties/SignedProperties>
	 *
	 * @param string $signatureFile This might be a standalone signature file
	 * @param string $certificateFile (optional) If provided it is an absolute path to the relevant .crt file or a path relative to the signature file
	 * @return bool
	 */
	public function verifyXAdES( $signatureFile, $certificateFile = null )
	{
		if ( ! filter_var( $signatureFile, FILTER_VALIDATE_URL ) )
			if ( ! file_exists( $signatureFile ) )
			{
				throw new XAdESException( "The signature file '$signatureFile' does not exist" );
			}

		try
		{
			// Load the XML to be signed
			$signatureDoc = new \DOMDocument();
			// Use @ to silence warnings output
			if ( @$signatureDoc->load( $signatureFile ) === false )
			{
				throw new XAdESException("Failed to load the XML from '$signatureFile'");
			}

			XmlCore::resetIds(); // Initialize the ids list.  Without this call no ids will be recorded.
			$root = Generic::fromNode( $signatureDoc );
			$signature = Generic::getSignature( $root, true );
			if ( ! $signature )
			{
				throw new XAdESException("Unable to find &lt;Signature> in the XML document");
			}
			if ( ! $signature->object || ! $signature->object->getQualifyingProperties() )
			{
				throw new XAdESException("Unable to find &lt;QualifyingProperties> in the signature.");
			}

			// Check there are no known gremlins
			$signature->validateElement();
			// $signature->traverse( function( XmlCore $node ) 
			// {
			// 	echo "{$node->getLocalName()}\n";
			// } );

			$qualifyingProperties = $signature->object->getQualifyingProperties();
			$this->currentNamespace = $qualifyingProperties->node->namespaceURI;

			// This is the base node for most queries
			/** @var SignedProperties */
			$signedProperties = $qualifyingProperties->getObjectFromPath(
				array( 'signedProperties' ),
				"Unable to find <signedProperties>"
			);

			$objDSig = $this->locateSignature( $signatureDoc );
			if ( ! $objDSig )
			{
				throw new XAdESException("Cannot locate Signature Node");
			}
			$this->canonicalizeSignedInfo();

			$return = $this->validateReference();

			if (! $return) {
				throw new XAdESException("Reference Validation Failed");
			}

			$objKey = $this->locateKey();
			if ( ! $objKey ) 
			{
				throw new XAdESException("We have no idea about the key");
			}
			$key = NULL;

			$objKeyInfo = XMLSecEnc::staticLocateKeyInfo( $objKey, $objDSig );

			if ( ! $objKeyInfo->key && empty( $key ) && $certificateFile ) 
			{
				// Load the certificate
				$certificateFile = self::resolve_path( $signatureDoc->documentURI, $certificateFile );
				if ( ! file_exists( $certificateFile ) )
				{
					throw new XAdESException( "Certificate file does not exist" );
				}
				$objKey->loadKey( $certificateFile, true );
			}

			if ( $this->verify( $objKey ) === 1 )
			{
				$certificateData = $objKeyInfo->getCertificateData();
				$serialNumber = $certificateData['serialNumber' ];
				$issuer = $certificateData['issuer' ];
	
				echo "XAdES signature validated using certificate with serial number '$serialNumber' for '$issuer'\n";
			} 
			else
			{
				throw new XAdESException( "The XAdES signature is not valid: it may have been tampered with." );
			}

			/**
			 * After verifying the signature, make sure all the properties are correct
			 */

			// Grab the serial number from the certificate used to compare it with the number stored in the signed properties
			$certificateData = $objKeyInfo->getCertificateData();
			$serialNumber = $certificateData['serialNumber' ];
			$issuerSerialNumber = null;

			/** @var Cert|CertV2 */
			$cert = $signedProperties->getObjectFromPath( 
				array( "SignedSignatureProperties", "SigningCertificate", "Cert" )
			);
			if ( $cert ) // Its not V2
			{
				// Get the serial number from the signed properties
				/** @var X509SerialNumber */
				$serialNumberElement = $cert->getObjectFromPath( 
					array( "IssuerSerial", "X509SerialNumber" ), 
					'The certificate serial number does not exist in the signature' 
				);

				$issuerSerialNumber = $serialNumberElement->text;
			}
			else // It is V2
			{
				// Look for an issuer certificate in the signature.  If one exists it 
				// will have been loaded by the call to XMLSecEnc::staticLocateKeyInfo()
				$issuerCertificateData = $objKeyInfo->getCertificateData( 1 );
				if ( $issuerCertificateData )
				{
					$serialNumber = $issuerCertificateData['serialNumber' ];
				}
				else
				{
					list( $certificate, $certificateInfo, $ocspResponderUrl, $issuerCertBytes, $issuerCertificate ) = array_values( Ocsp::getCertificate( $certificateData['cert'] ) );
					/** @var Sequence $certificate */
					/** @var CertificateInfo $certificateInfo */
					/** @var Sequence $issuerCertificate */
					if ( ! $issuerCertificate )
					{
						throw new XAdESException("The issuer certificate for the certificate with serial number 'serialNumber' " . 
							"that has been used to create the signature cannot be located.  " . 
							"It is not included in the signature and it cannot be accessed " . 
							"using information in the signing certificate." );
					}
					$serialNumber = $certificateInfo->extractSerialNumberAsInteger( $issuerCertificate, true );
				}

				$cert = $signedProperties->getObjectFromPath( 
					array( "SignedSignatureProperties", "SigningCertificateV2", "Cert" ),
					'A <Cert> element cannot be found within <SignedProperties>'
				);

				// Get the serial number from the signed properties
				/** @var X509SerialNumber */
				$IssuerSerialElement = $cert->getObjectFromPath( 
					array( "IssuerSerialV2" )
				);

				if ( $IssuerSerialElement )
				{
					$issuer = asSequence( (new Decoder())->decodeElement( base64_decode( $IssuerSerialElement->text ) ) );
					if ( $issuer )
					{
						$integer = $issuer->getFirstChildOfType( UniversalTagID::INTEGER );
						$issuerSerialNumber = $integer instanceof BigInteger
							? $integer->base10()
							: strval( $integer->getValue() );

						$generalNames = $issuer->getFirstChildOfType( UniversalTagID::SEQUENCE );

						$certificateInfo = $certificateInfo ?? new CertificateInfo();
						$dnNames = $certificateInfo->getDNStringFromNames( $generalNames );

						// Grab the issuer from the certificate used to compare it with the number stored in the signed properties
						/** @var string[] $issuer */
						$issuer = $certificateData['issuer'];

						// Compare issuer general names
						if ( ! \lyquidity\OCSP\CertificateInfo::compareIssuerStrings( $dnNames, $issuer ) )
						{
							throw new XAdESException('The certificate issuer in the signature does not match the certificate issuer number');
						}
					}
				}
			}

			// Compare serial number
			if ( $issuerSerialNumber )
				if ( $serialNumber != $issuerSerialNumber )
				{
					throw new XAdESException('The certificate serial number in the signature does not match the certificate serial number');
				}

			// If version 1.3.2 then there MAY be <IssuerSerialV2>
			if ( $cert instanceof Cert )
			{
				// Grab the issuer from the certificate used to compare it with the number stored in the signed properties
				/** @var string[] $issuer */
				$issuer = $certificateData['issuer'];

				$issuerElement = $cert->getObjectFromPath( 
					array( "IssuerSerial", "X509IssuerName" ),
					$this->currentNamespace == self::NamespaceUrl2003
						? 'The certificate issuer does not exist in the signature'
						: null
				);

				// Compare issuer general names
				if ( ! \lyquidity\OCSP\CertificateInfo::compareIssuerStrings( $issuerElement->text, $issuer ) )
				{
					throw new XAdESException('The certificate issuer in the signature does not match the certificate issuer number');
				}
			}

			$this->validateUnsignedSignatureProperties( $qualifyingProperties );

			// If the signature is being validated by the XAdES directly then the signature 
			// policy cannot be validated because it knows nothing about any specific policy.
			// Note get_class is used rather than instanceof so this class is checked explicitly.
			if ( get_class( $this ) == XAdES::class ) return;

			// Look for a signature policy and validate.  Will either return or throw an error.
			$this->validateSignaturePolicy( $signedProperties );
		}
		catch( XAdESException $ex )
		{
			error_log( $ex->getMessage() );
			if ( $ex->getPrevious() )
			{
				error_log( $ex->getPrevious()->getMessage() );
			}

			throw $ex;
		}
	}

	protected function validateUnsignedSignatureProperties( $qualifyingProperties )
	{
		if ( ! $qualifyingProperties ) return;

		// See if there are any signature timestamps
		/** @var UnsignedSignatureProperties */
		$unsignedSignatureProperties = $qualifyingProperties->getObjectFromPath( array( ElementNames::UnsignedProperties, ElementNames::UnsignedSignatureProperties ) );
		if ( ! $unsignedSignatureProperties ) return;

		foreach( $unsignedSignatureProperties->properties as $property )
		{
			if ( $property instanceof SignatureTimeStamp )
			{
				$this->validateSignatureTimeStamp( $property );
			}
			else if ( $property instanceof CounterSignature )
			{
				$this->validateCounterSignatures( $property );
			}
		}
	}

	/**
	 * Look for any timestamps and validatate them
	 * @param QualifyingProperties $qualifyingProperties
	 * @return void
	 * @throws XAdESException
	 */
	protected function validateSignatureTimeStamps( $qualifyingProperties )
	{
		if ( ! $qualifyingProperties ) return;

		// See if there are any signature timestamps
		/** @var UnsignedSignatureProperties */
		$unsignedSignatureProperties = $qualifyingProperties->getObjectFromPath( array( ElementNames::UnsignedProperties, ElementNames::UnsignedSignatureProperties ) );
		if ( ! $unsignedSignatureProperties ) return;

		foreach( $unsignedSignatureProperties->properties as $property )
		{
			if ( $property instanceof SignatureTimeStamp )
			{
				$this->validateSignatureTimeStamp( $property );
			}
		}
	}

	/**
	 * Validates a specific &lt;SignatureTimeStamp>
	 * @param SignatureTimeStamp $signatureTimeStamp
	 * @return void
	 * @throws XAdESException
	 */
	protected function validateSignatureTimeStamp( $signatureTimeStamp )
	{
		try
		{
			// First make sure the TST is signed correctly
			$der = base64_decode( $signatureTimeStamp->encapsulatedTimeStamp->text );

			// Now confirm the imprint
			// Begin by getting the <SignatureValue>
			$signature = $signatureTimeStamp->getRootSignature("Unable to locate the <Signature> element when validating a TST");
			/** @var SignatureValue */
			$signatureValue = $signature->getObjectFromPath( array( 'SignatureValue' ), "Unable to locate the <SignatureValue> element when validating a TST" );

			// Now valid and also pass the original data
			$canonicalized = $this->canonicalizeData( $signatureValue->node, $signatureTimeStamp->canonicalizationMethod );
			if ( TSA::validateTimeStampTokenFromDER( $der, $canonicalized ) ) return;

			throw new XAdESException("Unable to validate the TST");
		}
		catch( MissingResponseBytesException $ex )
		{
			error_log("TST validation was unable to check the revocation status of TSA certificate.  Try again later.");
		}
		catch(XAdESException $ex )
		{
			throw new XAdESException("An error has occurred validing a <SignatureTimeStamp>", $ex->getCode(), $ex );
		}
	}

	/**
	 * Find and validate any signature policy
	 * @param SignedProperties $signedProperties
	 * @throws XAdESException If no policy is found
	 */
	protected function validateSignaturePolicy( $signedProperties )
	{
		/** @var SignaturePolicyIdentifier */
		$signaturePolicyIdentifier = $signedProperties->getObjectFromPath( 
			array( "SignedSignatureProperties", "SignaturePolicyIdentifier" ), 
			"Unable to find <SignaturePolicyIdentifier> within <SignedProperties>"
		);

		$policyImplied = $signaturePolicyIdentifier->getObjectFromPath( array( "SignaturePolicyImplied" ) );

		// If the policy implied?  That is there is no policy document and instead some other agreed means to validate the properties
		if ( $policyImplied )
		{
			$this->validateImpliedPolicy( $signedProperties );
			return;
		}

		/** @var SignaturePolicyId */
		$signaturePolicyId = $signaturePolicyIdentifier->getObjectFromPath( 
			array( "SignaturePolicyId" ), 
			"<SignaturePolicyId> is not in <SignaturePolicyIdentifier>"
		);

		$policyIdentifier = $signaturePolicyId->getObjectFromPath( 
			array( "SigPolicyId", "Identifier" ), 
			"A signature policy element is expected but is not in the signature document."
		);

		$policyIdentifier = $policyIdentifier->text;

		$policyDocumentUrl = $this->getPolicyDocument( $policyIdentifier ) ;

		// If there is no policy document available via the class used then there's no point continuing.
		if ( ! $policyDocumentUrl ) 
			throw new XAdESException("Unable to access a policy document the policy identifier '$policyIdentifier'");

		// Is there a digest for the policy document?

		/** @var DigestValue */
		$policyDigest = $signaturePolicyId->getObjectFromPath( array( "SigPolicyHash", "DigestValue" ) );
		if ( $policyDigest )
		{
			$policyDigest = $policyDigest->text;

			// Gat the hash method
			/** @var DigestMethod */
			$policymethod = $signaturePolicyId->getObjectFromPath( array( "SigPolicyHash", "DigestMethod" ) );
			$policymethod = $policymethod ? $policymethod->algorithm : XMLSecurityDSig::SHA256;

			$policyDoc = $this->getXmlDocument( $policyDocumentUrl );
		
			// Create a new Security object 
			$output = $this->processTransforms( $policyDoc->documentElement, $policyDoc->documentElement, false );
			$digest = $this->calculateDigest( $policymethod, $output );

			if ( ! $policyDigest == $digest )
			{
				throw new XAdESException('The digest generated from the policy document does not match the digest contained in the poliocy document');
			}
		}

		$this->validateExplicitPolicy( $signedProperties, $policyDoc );
	}

	/**
	 * Utility function to create a DOMDocument for a url
	 * @param string $url
	 * @return \DOMDocument
	 */
	protected function getXmlDocument( $url )
	{
		$xml = file_get_contents( $url );
		$policyDoc = new \DOMDocument();
		$policyDoc->loadXML( $xml );
		return $policyDoc;
	}

	/**
	 * Its expected this will be overridden in a descendent class
	 * @var string $policyIdentifier
	 * @return string A path or URL to the policy document
	 */
	protected function getPolicyDocument( $policyIdentifier )
	{
		return null;
	}

	/**
	 * A descendent can provide a method to validate the signature properties when the policy is implied
	 *
	 * @param SignedProperties $signedProperties
	 * @return void
	 */
	protected function validateImpliedPolicy( $signedProperties )
	{
		// Do nothing
	}

	/**
	 * Overridden by a descendent to check the policy rules are met in the signature
	 *
	 * @param SignedProperties $signedProperties
	 * @param \DOMElement $policyDocument
	 * @return void
	 * @throws XAdESException If the signature does not meet the policy rules
	 */
	protected function validateExplicitPolicy( $signedProperties, $policyDocument )
	{
		// Do nothing
	}

	/**
     * Adds a &lt;SignatureTimeStamp> which stores DER encoded response from a 
	 * Time Stamp Authority (TSA) as defined in RFC3161.  The data passed is 
	 * hashed, in this case using SHA256 and it the hash that is sent to the
	 * TSA and which is used by the TSA to create a signature along with a record
	 * of the date and time. 
     * 
     * @param \DOMDocument $doc A DOMDocument instance that includes &lt;ds:SignatureValue>
     * @param string $signatureId The id of <ds:Signature> and isused as the property @Target of &lt;QualifyingProperties>
     * @param string $propertyId An id to use to identify the property.  Currently not used.
     * @return void
     */
    public function addTimestamp( $doc, $signatureId, $propertyId = 'timestamp' )
	{
		// Things done here may be sometimes repeat those done elsewhere.  This is so the 
		// function can be called independently of creating a new signature.
		$xpath = new \DOMXPath( $doc );
        $xpath->registerNamespace( 'ds', XMLSecurityDSig::XMLDSIGNS );
        $nodes = $xpath->query( "//ds:Signature[\"@Id={$this->signatureId}\"]" );
		if ( ! $nodes || ! $nodes->count() )
		{
			throw new XAdESException( "A timestamp cannot be created because there is no existng signaure with @Id '$signatureId'" );
		}

		/** @var \DOMElement */
		$signature = $nodes[0];

        $nodes = $xpath->query( "./ds:SignatureValue", $signature );
		if ( ! $nodes || ! $nodes->count() )
		{
			throw new XAdESException( "A timestamp cannot be created because there is no existng signaure with @Id '$signatureId'" );
		}

		/** @var \DOMElement */
		$signatureValue = $nodes[0];

		$canonicalized = $this->canonicalizeData( $signatureValue, $this->canonicalMethod );
		$der = TSA::getTimestampDER( $canonicalized );

		$timestamp = new SignatureTimeStamp(
			null,
			$this->canonicalMethod,
			base64_encode( $der ),
			"{$signatureId}_{$propertyId}"
		);

		$timestamp->validateElement();

        $xpath->registerNamespace( 'xa', $this->currentNamespace );
		$qualifyingProperties = $xpath->query("./ds:Object/xa:QualifyingProperties", $signature );

		if ( ! $qualifyingProperties || $qualifyingProperties->count() != 1 )
			throw new XAdESException("<QualifyingProperties> not found or invalid");

		$qp = Generic::fromNode( $qualifyingProperties[0] );

		if ( ! $qp instanceof QualifyingProperties )
		{
			throw new XAdESException("Unable to find the <QualifyingProperties> element");
		}

		/** @var XmlCore */
		$startPoint = null;
		/** @var XmlCore */
		$parent = null;

		if ( ! $qp->unsignedProperties )
		{
			$parent = $qp;
			$startPoint = new UnsignedProperties(
				new UnsignedSignatureProperties(
					$timestamp					
				)
			);
		}
		else if ( ! $qp->unsignedProperties->unsignedSignatureProperties )
		{
			$parent = $qp->unsignedProperties;
			$startPoint = new UnsignedSignatureProperties(
				$timestamp
			);
		}
		else
		{
			$parent = $qp->unsignedProperties->unsignedSignatureProperties;
			$startPoint = $timestamp;
		}

		$parent->validateElement();

		// OK, time to write the timestamp
		$startPoint->generateXml( $parent->node );
	}

	/**
	 * Add a counter signature to an exising signature
	 *
	 * @param SignedDocumentResourceInfo $xmlResource
	 * @param CertificateResourceInfo $certificateResource
	 * @param KeyResourceInfo $keyResource
	 * @param SignatureProductionPlace|SignatureProductionPlaceV2 $signatureProductionPlace
	 * @param SignerRole|SignerRoleV2 $signerRole
	 * @param bool $canonicalizedSignedInfo (reference, optional: default = false) A string when the canonicalized SI should be returned and the signature not signed
	 * @return XAdES|bool The instance will be returned.
	 */
	public function addCounterSignature( $xmlResource, $certificateResource, $keyResource = null, $signatureProductionPlace = null, $signerRole = null, &$canonicalizedSignedInfo = false )
	{
		$canonicalizeOnly = is_string( $canonicalizedSignedInfo );

		if ( is_string( $xmlResource ) )
		{
			// If a simple string is passed in, assume it is a file name
			// Any problems with this assumption will appear later
			$xmlResource = new SignedDocumentResourceInfo( $xmlResource, ResourceInfo::file );
		}
		else
		{
			// Make sure the argument is the correct type
			if ( ! $xmlResource instanceof SignedDocumentResourceInfo )
				throw new XAdESException("The input resource must be a path to an XML file or an SignedDocumentResourceInfo instance");
		}

		if ( is_string( $certificateResource ) )
		{
			// If a simple string is passed in, assume it is a file name
			// Any problems with this assumption will appear later
			$certificateResource = new CertificateResourceInfo( $certificateResource, ResourceInfo::file );
		}
		else
		{
			// Make sure the certificate argument is the correct type
			if ( ! $certificateResource instanceof CertificateResourceInfo )
				throw new XAdESException("The certificate resource must be a CertificateResourceInfo instance");
		}

		if ( ! $canonicalizeOnly )
		{
			if ( is_string( $keyResource ) )
			{
				// If a simple string is passed in, assume it is a file name
				// Any problems with this assumption will appear later
				$keyResource = new KeyResourceInfo( $keyResource, ResourceInfo::file );
			}
			else
			{
				// Make sure the key argument is the correct type
				if ( ! $keyResource instanceof KeyResourceInfo )
					throw new XAdESException("The key resource must be a KeyResourceInfo instance");
			}
		}
	
		// Load the existing document containing the signature
		if ( $xmlResource->isFile() )
		{
			if ( ! file_exists( $xmlResource->resource ) )
			{
				throw new XAdESException( "XML file does not exist" );
			}
	
			// Load the XML to be signed
			$doc = new \DOMDocument();
			$doc->load( $xmlResource->resource );
		}
		else if ( $xmlResource->isXmlDocument() )
		{
			$doc = $xmlResource->resource;
		}
		else if ( $xmlResource->isString() || $xmlResource->isURL() )
		{
			// Load the XML to be signed
			$doc = new \DOMDocument();
			$doc->load( $xmlResource->resource );
		}
		else
		{
			throw new XAdESException( "The resource supplied representing the document to be signed is not valid." );
		}

		if ( $xmlResource->isXmlDocument() || $xmlResource->isString() || $xmlResource->isURL() )
		{
			// When the source is a string or url or a DOM document then there must be a location and file name defined
			if ( ! $xmlResource->saveLocation || ! $xmlResource->saveFilename )
			{
				throw new XAdESException("If the input XML document is provided as a string, a DOM node or a URL then a save location and a save file name must be provided.");
			}
		}

		$this->fileBeingSigned = $xmlResource;
		$this->signatureId = $xmlResource->id;
	
		$xpath = new \DOMXPath( $doc );
		$xpath->registerNamespace( 'ds', XMLSecurityDSig::XMLDSIGNS );
		$xpath->registerNamespace( 'xa', $this->currentNamespace );
		$query = '//ds:Signature';
		if ( $xmlResource->id )
			$query .= "[@Id='{$this->signatureId}']";

		$signatures = $xpath->query( $query );
		$hasSignature = $signatures->count() > 0;
		if ( ! $hasSignature )
		{
			$message = "The input document must already contain a signature (the one to counter sign)";
			if ( $xmlResource->id )
				$message .= ". The <Signature> must have @Id '{$xmlResource->id}'";

			throw new XAdESException( $message );
		}
		unset( $xpath );

		// Use a default canonicalization method
		$canonicalizationMethod = XMLSecurityDSig::C14N;

		// Create a signature object
		/** @var Signature */
		$signature = Generic::fromNode( $signatures[0] );

		// Create a counter signature
		$xmlDSig = new XMLSecurityDSig( $this->prefix ?? XMLSecurityDSig::defaultPrefix, $xmlResource->counterSignatureId );
		$xmlDSig->setCanonicalMethod( $canonicalizationMethod );

		// Create a reference id to use 
		$referenceId = XMLSecurityDSig::generateGUID('counter-signature-');
		$this->signatureId = $xmlResource->counterSignatureId ?? null;

		if ( $signatureProductionPlace || $signerRole )
		{
			// Create a Qualifying properties hierarchy
			$signedSignatureProperties = null;
			$signedProperties = XMLSecurityDSig::generateGUID('signed-properties-');

			$qualifyingProperties = $this->createQualifyingProperties(
				$this->signatureId, // Id of the signature to sign
				$certificateResource->isFile() ? file_get_contents( $certificateResource->resource ) : $certificateResource->resource, 
				$signatureProductionPlace, 
				$signerRole, 
				$signedSignatureProperties, // Id of the signedSignatureProperties of the counter signature
				$this->signatureId, // Target - the id of the signature being created. This is added to the data object element.
				$signedProperties
			);

			// A counter signature is NEVER detatched so add a prefix so when the signature 
			// is attached the importNode function does not add a 'default' prefix.
			$qualifyingProperties->traverse( function( XmlCore $node )
			{
				if ( $node->defaultNamespace && $node->defaultNamespace != $this->currentNamespace ) 
					return;
				$node->prefix = 'xa';
			} );

			// Add the Xml to the signature
			$object = $xmlDSig->addObject( null );
			$qualifyingProperties->generateXml( $object );

			// Get the specific node to be included in the signature
			$xpath = $xmlDSig->getXPathObj();
			$xpath->registerNamespace( 'xa', $this->currentNamespace );
			$nodes = $xpath->query("./xa:QualifyingProperties/xa:SignedProperties[\"@Id={$referenceId}\"]", $object );
			if ( ! $nodes->length )
				throw new XAdESException();
			unset( $object );

			$xmlDSig->addReference(
				$nodes[0],
				XMLSecurityDSig::SHA256, 
				array( // Transforms
					$canonicalizationMethod
				),
				array( // Options
					'force_uri' => $signedProperties,
					'overwrite' => false,
					'type' => self::ReferenceType
				)
			);
		}

		$xmlDSig->addReference(
			$signature->signatureValue->node,
			XMLSecurityDSig::SHA256,
			$canonicalizationMethod,
			array( // Options
				'force_uri' => $xmlResource->id ? '#' . $xmlResource->id : true,
				'type' => self::counterSignatureTypeUrl,
				'id' => $referenceId
			)
		);

		if ( $canonicalizeOnly )
		{
			$canonicalizedSignedInfo = $xmlDSig->getSignedInfoCanonicalized( XMLSecurityKey::RSA_SHA256 );
		}
		else
		{
			// Create a new (private) Security key
			$dsigKey = new XMLSecurityKey( XMLSecurityKey::RSA_SHA256, array( 'type'=>'private' ) );

			if ( $keyResource->isFile() )
			{
				if ( ! file_exists( $keyResource->resource ) )
				{
					throw new XAdESException( "Key file does not exist" );
				}

				// Load the signing key
				$dsigKey->loadKey( $keyResource->resource, true );
			}
			else if ( $keyResource->isString() | $keyResource->isDER() )
			{
				// Load the signing key
				$dsigKey->loadKey( $keyResource->resource, false );
			}
			else
			{
				throw new XAdESException( "The resource supplied representing the private key to be recorded in the signature is not valid." );
			}

			/*
			 * If key has a passphrase, set it using
			 * $objKey->passphrase = '<passphrase>';
			*/

			// Sign the XML file
			$xmlDSig->sign( $dsigKey );
		}

		// Add the associated public key to the signature
		if ( $certificateResource->isFile() )
		{
			if ( ! file_exists( $certificateResource->resource ) )
			{
				throw new XAdESException( "Certificate file does not exist" );
			}

			// Add the associated public key to the signature
			$certificate = file_get_contents( $certificateResource->resource );
			$xmlDSig->add509Cert( $certificate );
		}
		else if ( $certificateResource->isString() | $certificateResource->isDER() )
		{
			// Add the associated public key to the signature
			$certificate = $certificateResource->resource;
			$xmlDSig->add509Cert( $certificateResource->resource, $certificateResource->isPEM(), $certificateResource->isURL() );
		}
		else
		{
			throw new XAdESException( "The resource supplied representing the certificate to be recorded in the signature is not valid." );
		}

		// Make sure there are node on the path from <Signature> to 
		// <CounterSignature> and add elements where they are missing
		$object = $signature->object;
		if ( ! $object )
		{
			$object = $signature->object = new Obj();
			$signature->object->generateXml( $signature->node );
		}

		/** @var QualifyingProperties */
		$qp = $object->getObjectFromPath(array( ElementNames::QualifyingProperties ) );
		if ( ! $qp )
		{
			$qp = $object->addChildNode( new QualifyingProperties() );
			$qp->generateXml( $object->node );
		}

		$unsignedProperties = $qp->unsignedProperties;
		if ( ! $unsignedProperties )
		{
			$unsignedProperties = $qp->unsignedProperties = new UnsignedProperties();
			$unsignedProperties->generateXml( $qp->node );
		}

		$unsignedSignatureProperties = $unsignedProperties->unsignedSignatureProperties;
		if ( ! $unsignedSignatureProperties )
		{
			$unsignedSignatureProperties = $qp->unsignedProperties = new UnsignedSignatureProperties();
			$unsignedSignatureProperties->generateXml( $unsignedProperties->node );
		}

		/** @var CounterSignature */
		$counterSignature = $unsignedSignatureProperties->getObjectFromPath(array( ElementNames::CounterSignature ) );
		if ( ! $counterSignature )
		{
			/** @var CounterSignature */
			$counterSignature = $unsignedSignatureProperties->addProperty( new CounterSignature() );
			$counterSignature->generateXml( $unsignedSignatureProperties->node );
		}

		$location = $xmlResource->saveLocation
			? "{$xmlResource->saveLocation}"
			: (
				$xmlResource->isFile()
					? dirname( $xmlResource->resource )
					: __DIR__
			  );

		$filename = $xmlResource->saveFilename
			? "{$xmlResource->saveFilename}"
			: (
				$xmlResource->isFile()
					? basename( $xmlResource->resource )
					: self::SignatureFilename
			  );

		// Add 'xml' extension if one ios not provided
		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) )
			$filename .= '.xml';

		// Append the counter signature <Signature> and save
		$xmlDSig->appendSignature( $counterSignature->node );
		$doc->save( $this->getSignatureFilename( $location, $filename ), LIBXML_NOEMPTYTAG );

		return $this;
	}

	/**
	 * Look for any counter signatures and validatate them
	 * @param CounterSignature $counterSignature
	 * @return void
	 * @throws XAdESException
	 */
	protected function validateCounterSignatures( $counterSignature )
	{
		if ( ! $counterSignature ) return;

		foreach( $counterSignature->properties as $property )
		{
			if ( $property instanceof Signature )
			{
				$this->validateCounterSignature( $property );
			}
		}
	}

	/**
	 * Validatate a specific counter signature
	 * @param Signature $signature
	 * @return void
	 * @throws XAdESException
	 */
	protected function validateCounterSignature( $signature )
	{
		// Create a new Security object 
		$objXMLSecDSig  = new XMLSecurityDSig();

		$objDSig = $objXMLSecDSig->locateSignature( $signature->node->ownerDocument );
		if ( ! $objDSig )
		{
			throw new XAdESException("Cannot locate Signature Node");
		}
		$objXMLSecDSig->canonicalizeSignedInfo();
		
		$return = $objXMLSecDSig->validateReference();

		if ( ! $return)
		{
			throw new XAdESException("Reference Validation Failed");
		}
		
		$objKey = $objXMLSecDSig->locateKey();
		if ( ! $objKey ) 
		{
			throw new XAdESException("We have no idea about the key");
		}
		$key = NULL;

		$objKeyInfo = XMLSecEnc::staticLocateKeyInfo( $objKey, $objDSig );

		if ( ! $objKeyInfo || ! $objKeyInfo->key ) 
		{
			throw new XAdESException("Unable to locate a public from the certificate used to create the counter signature.");
		}

		if ( $objXMLSecDSig->verify( $objKey ) === 1 )
		{
			$certificateData = $objKeyInfo->getCertificateData();
			$serialNumber = $certificateData['serialNumber' ];
			$issuer = $certificateData['issuer' ];

			echo "Counter signature validated using certificate with serial number '$serialNumber' for '$issuer'\n";
		} 
		else
		{
			$path = $signature->node->getNodePath();
			throw new XAdESException( "The counter signature is not valid: it may have been tampered with." . "  The node path is: $path" );
		}
	}
}