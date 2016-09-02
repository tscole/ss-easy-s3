<?php

	/**
	 * Class S3FileExtension
	 * Contains utility methods to connect to S3 bucket
	 * @package
	 *
	 * @property bool   SyncToS3
	 * @property string S3FileURL
	 * @property File   owner
	 */
	class S3ImageExtension extends DataExtension
	{

		/**
		 * Injected facade
		 * @var S3Facade
		 */
		public $s3facade;
		/**
		 * Mechanism to avoid endless loop of afterWrite calls
		 * @var bool
		 */
		private $skipAfterWrite = false;

		static $db = array(
			'SyncToS3'  => 'Boolean',
			'S3FileURL' => 'Varchar(500)',
		);

		static $defaults = array(
			'SyncToS3' => 1,
		);

		static $summary_fields = array();

		static $indexes = array();

		static $has_one = array();

		static $has_many = array();

		public function updateCMSFields(FieldList $fields)
		{
			$fields->push(new CheckboxField('SyncToS3'));
		}

		public function onBeforeWrite()
		{
			parent::onBeforeWrite();

			if(is_null($this->owner->SyncToS3))
			{
				//Include in SSync by default
				$this->owner->SyncToS3 = 1;
			}
		}

		/**
		 * Writes the image to S3 bucket after it's written locally.
		 */
		public function onAfterWrite()
		{
			parent::onAfterWrite();
			//			var_dump($this->skipAfterWrite, $this->owner->SyncToS3);

			//See if we should write it to S3. Do so if we must
			if($this->owner->SyncToS3 && $this->skipAfterWrite == false)
			{
				$client = $this->s3facade->setupS3Client();
				try
				{
					//@todo Only update if file content changes, not always
					$bucket = $this->s3facade->getBucket();
					$key = $this->owner->getFilename();
					$acl = 'public-read';
					$putInfo = array(
						//Bucket Name
						'Bucket' => $bucket,
						//Where we're putting it
						'Key'    => $key,
						//File content
						'Body'   => fopen($this->owner->getFullPath(), 'rb'),
						//Amazon specific permission
						'ACL'    => $acl,
					);
					$result = $client->putObject($putInfo);
					$this->owner->S3FileURL = $result['ObjectURL'];
					$this->skipAfterWrite = true;
					$this->owner->write();
					$this->s3facade->InvalidateCache($key);

				}catch(\Aws\S3\Exception\S3Exception $e)
				{
					SS_Log::log($e->getMessage(), SS_Log::ERR);
				}catch(\Aws\CloudFront\Exception\CloudFrontException $e)
				{
					SS_Log::log($e->getMessage(), SS_Log::ERR);
				}
			}
		}

		/**
		 * Deletes the file from S3 bucket as well.
		 */
		public function onAfterDelete()
		{
			parent::onAfterDelete();
			$client = $this->s3facade->setupS3Client();
			$bucket = $this->s3facade->getBucket();
			$key = $this->owner->getFilename();
			$objExists = $client->doesObjectExist($bucket, $key);
			if($objExists)
			{
				$deleteInfo = array(
					'Bucket' => $bucket,
					'Key'    => $key,
				);
				try
				{
					//Check if file has changed, and update if that's the case
					$client->deleteObject($deleteInfo);
					$this->s3facade->InvalidateCache($key);
				}catch(\Aws\S3\Exception\S3Exception $e)
				{
					SS_Log::log($e->getMessage(), SS_Log::ERR);
				}catch(\Aws\CloudFront\Exception\CloudFrontException $e)
				{
					SS_Log::log($e->getMessage(), SS_Log::ERR);
				}
			}
		}

		public function getRelativePath()
		{
			$result = parent::getRelativePath();

			if($this->SyncToS3 && !empty($this->S3FileURL))
			{
				//Return s3 path
				$result = $this->S3FileURL;
			}

			return $result;
		}

		public function getFilename()
		{
			$result = parent::getFilename();

			if($this->SyncToS3 && !empty($this->S3FileURL))
			{
				//Return s3 path
				$result = $this->S3FileURL;
			}

			return $result;
		}


	}

