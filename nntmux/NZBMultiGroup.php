<?php
namespace nntmux;

use app\extensions\util\Versions as li3Versions;
use app\models\Settings;
use nntmux\db\DB;
use nntmux\utility\Utility;

/**
 * Class for reading and writing NZB files on the hard disk,
 * building folder paths to store the NZB files.
 */
class NZBMultiGroup
{

	/**
	 * @var
	 */
	private $_tableNames;

	/**
	 * @var
	 */
	private $_collectionsQuery;

	/**
	 * @var
	 */
	private $_binariesQuery;

	/**
	 * @var
	 */
	private $_partsQuery;

	/**
	 * @var
	 */
	private $_nzbCommentString;

	/**
	 * Default constructor.
	 *
	 *
	 * @access public
	 */
	public function __construct()
	{
		$this->pdo = new DB();
		$this->nzb = new NZB();
		$nzbSplitLevel = Settings::value('..nzbsplitlevel');
		$this->nzbSplitLevel = (empty($nzbSplitLevel) ? 1 : $nzbSplitLevel);
	}

	/**
	 * Initiate class vars when writing NZB's.
	 *
	 *
	 * @access public
	 */
	public function initiateForMgrWrite()
	{

		$this->_collectionsQuery = "
			SELECT c.*, UNIX_TIMESTAMP(c.date) AS udate,
				g.name AS groupname
			FROM mgr_collections c
			INNER JOIN groups g ON c.group_id = g.id
			WHERE c.releaseid = ";
		$this->_binariesQuery = "
			SELECT b.id, b.name, b.totalparts
			FROM mgr_binaries b
			WHERE b.collection_id = %d
			ORDER BY b.name ASC";
		$this->_partsQuery = "
			SELECT DISTINCT(p.messageid), p.size, p.partnumber
			FROM mgr_parts p
			WHERE p.binaryid = %d
			ORDER BY p.partnumber ASC";

		$this->_nzbCommentString = sprintf(
			"NZB Generated by: NNTmux %s %s",
			(new li3Versions())->getGitTagInRepo(),
			Utility::htmlfmt(date('F j, Y, g:i a O'))
		);
	}

	/**
	 * Write an NZB to the hard drive for a single release.
	 *
	 * @param int    $relID   The ID of the release in the DB.
	 * @param string $relGuid The guid of the release.
	 * @param string $name    The name of the release.
	 * @param string $cTitle  The name of the category this release is in.
	 *
	 * @return bool Have we successfully written the NZB to the hard drive?
	 *
	 * @access public
	 */
	public function writeMgrNZBforReleaseId($relID, $relGuid, $name, $cTitle)
	{
		$collections = $this->pdo->queryDirect($this->_collectionsQuery . $relID);

		if (!$collections instanceof \Traversable) {
			return false;
		}

		$xmlwrtr = new \XMLWriter();
		$xmlwrtr->openMemory();
		$xmlwrtr->setIndent(true);
		$xmlwrtr->setIndentString('  ');

		$nzb_guid = '';

		$xmlwrtr->startDocument('1.0', 'UTF-8');
		$xmlwrtr->startDtd(NZB::NZB_DTD_NAME, NZB::NZB_DTD_PUBLIC, NZB::NZB_DTD_EXTERNAL);
		$xmlwrtr->endDtd();
		$xmlwrtr->writeComment($this->_nzbCommentString);

		$xmlwrtr->startElement('nzb');
		$xmlwrtr->writeAttribute('xmlns', NZB::NZB_XML_NS);
		$xmlwrtr->startElement('head');
		$xmlwrtr->startElement('meta');
		$xmlwrtr->writeAttribute('type', 'category');
		$xmlwrtr->text($cTitle);
		$xmlwrtr->endElement();
		$xmlwrtr->startElement('meta');
		$xmlwrtr->writeAttribute('type', 'name');
		$xmlwrtr->text($name);
		$xmlwrtr->endElement();
		$xmlwrtr->endElement(); //head

		foreach ($collections as $collection) {
			$binaries = $this->pdo->queryDirect(sprintf($this->_binariesQuery, $collection['id']));
			if (!$binaries instanceof \Traversable) {
				return false;
			}

			$poster = $collection['fromname'];

			foreach ($binaries as $binary) {
				$parts = $this->pdo->queryDirect(sprintf($this->_partsQuery, $binary['id']));
				if (!$parts instanceof \Traversable) {
					return false;
				}

				$subject = $binary['name'] . '(1/' . $binary['totalparts'] . ')';
				$xmlwrtr->startElement('file');
				$xmlwrtr->writeAttribute('poster', $poster);
				$xmlwrtr->writeAttribute('date', $collection['udate']);
				$xmlwrtr->writeAttribute('subject', $subject);
				$xmlwrtr->startElement('groups');
				if (preg_match_all('#(\S+):\S+#', $collection['xref'], $matches)) {
					foreach ($matches[1] as $group) {
						$xmlwrtr->writeElement('group', $group);
					}
				}
				$xmlwrtr->endElement(); //groups
				$xmlwrtr->startElement('segments');
				foreach ($parts as $part) {
					if ($nzb_guid === '') {
						$nzb_guid = $part['messageid'];
					}
					$xmlwrtr->startElement('segment');
					$xmlwrtr->writeAttribute('bytes', $part['size']);
					$xmlwrtr->writeAttribute('number', $part['partnumber']);
					$xmlwrtr->text($part['messageid']);
					$xmlwrtr->endElement();
				}
				$xmlwrtr->endElement(); //segments
				$xmlwrtr->endElement(); //file
			}
		}
		$xmlwrtr->endElement(); //nzb
		$xmlwrtr->endDocument();
		$path = ($this->nzb->buildNZBPath($relGuid, $this->nzbSplitLevel, true) . $relGuid . '.nzb.gz');
		$fp = gzopen($path, 'wb7');
		if (!$fp) {
			return false;
		}
		gzwrite($fp, $xmlwrtr->outputMemory());
		gzclose($fp);
		unset($xmlwrtr);
		if (!is_file($path)) {
			echo "ERROR: $path does not exist.\n";

			return false;
		}
		// Mark release as having NZB.
		$this->pdo->queryExec(
			sprintf('
				UPDATE releases SET nzbstatus = %d %s WHERE id = %d',
				NZB::NZB_ADDED, ($nzb_guid === '' ? '' : ', nzb_guid = UNHEX( ' . $this->pdo->escapeString(md5($nzb_guid)) . ' )'),
				$relID
			)
		);
		// Delete CBP for release that has its NZB created.
		$this->pdo->queryExec(
			sprintf('
			DELETE c, b, p FROM mgr_collections c JOIN mgr_binaries b ON(c.id=b.collection_id) STRAIGHT_JOIN mgr_parts p ON(b.id=p.binaryid) WHERE c.releaseid = %d',
				$relID
			)
		);
		// Chmod to fix issues some users have with file permissions.
		chmod($path, 0777);

		return true;
	}
}
