CREATE TABLE `tbl_messages_queue` (
  `messageId_n` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dateAdded_d` int(10) unsigned NOT NULL,
  `processing_n` tinyint(1) unsigned DEFAULT '0',
  `type_c` varchar(32) NOT NULL,
  `destination_c` mediumtext,
  `body_c` mediumtext,
  `subject_c` varchar(255) DEFAULT NULL,
  `attachments_c` text,
  `attempts_n` smallint(5) unsigned DEFAULT '0',
  `lastAttemptDate_d` int(10) unsigned DEFAULT NULL,
  `lastError_c` text,
  `failed_c` mediumtext,
  PRIMARY KEY (`messageId_n`),
  KEY `processing_n` (`processing_n`) USING BTREE,
  KEY `attempts_n` (`attempts_n`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT