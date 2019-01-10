<?php

/**
 * @file plugins/generic/useGlobalSender/UseGlobalSenderPlugin.inc.php
 *
 * Copyright (c) 2013-2018 Simon Fraser University
 * Copyright (c) 2000-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class UseGlobalSenderPlugin
 * @ingroup plugins_generic_useGlobalSender
 *
 * @brief Replaces the default mail send method and replaces all from fields with the envelope sender value
 */
import('lib.pkp.classes.plugins.GenericPlugin');

class UseGlobalSenderPlugin extends GenericPlugin {

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success) {
			HookRegistry::register('Mail::send', array(&$this, 'sendMail'));
		}
		return $success;
	}

	/**
	* @copydoc Plugin::isSitePlugin()
	*/
	function isSitePlugin() {
		// This is a site-wide plugin.
		return true;
	}

	/**
	 * @copydoc LazyLoadPlugin::getName()
	 */
	function getName() {
		return 'useGlobalSenderPlugin';
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.useGlobalSender.name');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.useGlobalSender.description');
	}

	/**
	* @copydoc Plugin::getInstallSitePluginSettingsFile()
	*/
	function getInstallSitePluginSettingsFile() {
		return $this->getPluginPath() . '/settings.xml';
	}

	/**
	 * Hook to Mail::send and replace with modified code
	 * @param $this array
	 * @return boolean
	 * @see Mail::send for the hook call.
	 */
	function sendMail($hookName, $args) {
		$mailTemplate = $args[0];

		// Replace all the private parameters for this message.
		$mailBody = $mailTemplate->getBody();
		if (is_array($mailTemplate->privateParams)) {
			foreach ($mailTemplate->privateParams as $name => $value) {
				$mailBody = str_replace($name, $value, $mailBody);
			}
		}
		$mailer = new PHPMailer();
		$mailer->IsHTML(true);
		$mailer->Encoding = 'base64';
		if (Config::getVar('email', 'smtp')) {
			$mailer->IsSMTP();
			$mailer->Port = Config::getVar('email', 'smtp_port');
			if (($s = Config::getVar('email', 'smtp_auth')) != '') {
				$mailer->SMTPSecure = $s;
				$mailer->SMTPAuth = true;
			}
			$mailer->Host = Config::getVar('email', 'smtp_server');
			$mailer->Username = Config::getVar('email', 'smtp_username');
			$mailer->Password = Config::getVar('email', 'smtp_password');
			if (Config::getVar('debug', 'show_stacktrace')) {
				// debug level 3 represents client and server interaction, plus initial connection debugging
				$mailer->SMTPDebug = 3;
				$mailer->Debugoutput = 'error_log';
			}
		}
		$mailer->CharSet = Config::getVar('i18n', 'client_charset');
		if (($t = $mailTemplate->getContentType()) != null) $mailer->ContentType = $t;
		$mailer->XMailer = 'Public Knowledge Project Suite v3';
		$mailer->WordWrap = MAIL_WRAP;
		foreach ((array) $mailTemplate->getHeaders() as $header) {
			$mailer->AddCustomHeader($header['key'], $mailer->SecureHeader($header['content']));
		}
		if (($f = $mailTemplate->getFrom()) != null) {
			if (Config::getVar('email', 'force_default_envelope_sender') && Config::getVar('email', 'default_envelope_sender')) {
				/* If a DMARC compliant RFC5322.From was requested we need to promote the original RFC5322.From into a Reply-to header
				 * and then munge the RFC5322.From */
				$alreadyExists = false;
				foreach ((array) $mailTemplate->getReplyTo() as $r) {
					if ($r['email'] === $f['email']) {
						$alreadyExists = true;
					}
				}
				if (!$alreadyExists) {
					$mailer->AddReplyTo($f['email'], $f['name']);
				}

				$request = Application::getRequest();
				$site = $request->getSite();

				// Munge the RFC5322.From
				$f['name'] = $f['name'] . ' via ' . $site->getLocalizedTitle();
				$f['email'] = Config::getVar('email', 'default_envelope_sender');
			}
			// this sets both the envelope sender (RFC5321.MailFrom) and the From: header (RFC5322.From)
			$mailer->SetFrom($f['email'], $f['name']);
		}
		// Set the envelope sender (RFC5321.MailFrom)
		if (($s = $mailTemplate->getEnvelopeSender()) != null) $mailer->Sender = $s;
		foreach ((array) $mailTemplate->getReplyTo() as $r) {
			$mailer->AddReplyTo($r['email'], $r['name']);
		}
		foreach ((array) $mailTemplate->getRecipients() as $recipientInfo) {
			$mailer->AddAddress($recipientInfo['email'], $recipientInfo['name']);
		}
		foreach ((array) $mailTemplate->getCcs() as $ccInfo) {
			$mailer->AddCC($ccInfo['email'], $ccInfo['name']);
		}
		foreach ((array) $mailTemplate->getBccs() as $bccInfo) {
			$mailer->AddBCC($bccInfo['email'], $bccInfo['name']);
		}
		$mailer->Subject = $mailTemplate->getSubject();
		$mailer->Body = $mailBody;
		$mailer->AltBody = PKPString::html2text($mailBody);
		$remoteAddr = $mailer->SecureHeader(Request::getRemoteAddr());
		if ($remoteAddr != '') $mailer->AddCustomHeader("X-Originating-IP: $remoteAddr");
		foreach ((array) $mailTemplate->getAttachments() as $attachmentInfo) {
			$mailer->AddAttachment(
				$attachmentInfo['path'],
				$attachmentInfo['filename'],
				'base64',
				$attachmentInfo['content-type']
			);
		}
		try {
			$success = $mailer->Send();
			if (!$success) {
				error_log($mailer->ErrorInfo);
				return true;
			}
		} catch (phpmailerException $e) {
			error_log($mailer->ErrorInfo);
			return true;
		}
		return false;

	}

}

