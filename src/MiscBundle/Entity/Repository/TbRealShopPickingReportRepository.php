<?php

namespace MiscBundle\Entity\Repository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\ResultSetMapping;
use forestlib\Doctrine\ORM\LimitableNativeQuery;
use MiscBundle\Entity\TbRealShopPickingReport;

/**
 * TbRealShopPickingReportRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class TbRealShopPickingReportRepository extends BaseRepository
{
  // レポートステータス
  const REPORT_STATUS_NONE      = 0;
  const REPORT_STATUS_IMPORTED  = 1;
  const REPORT_STATUS_DELETED   = 9; // 未使用

  /**
   * 一覧データ取得
   * @param array $conditions
   * @param array $orders
   * @param int $page
   * @param int $limit
   * @return \Knp\Bundle\PaginatorBundle\Pagination\SlidingPagination
   */
  public function getListData($conditions = [], $orders = [], $page = 1, $limit = 20)
  {
    /** @var EntityManager $em */
    $em = $this->getEntityManager();

    $sqlSelect = <<<EOD
      SELECT
          T.picking_date
        , T.number
        , T.account_name
        , T.product_count
        , T.move_num
        , COALESCE(T.label_types, 'tag') AS label_types
EOD;
    $sqlBody = <<<EOD
      FROM (
        SELECT
            pr.picking_date
          , pr.number
          , GROUP_CONCAT(DISTINCT pr.create_account_name ORDER BY pr.create_account_id SEPARATOR '/') AS account_name
          , COUNT(DISTINCT pr.ne_syohin_syohin_code) AS product_count
          , SUM(pr.move_num) AS move_num
          , GROUP_CONCAT(DISTINCT i.label_type ORDER BY CASE WHEN i.label_type = 'tag' THEN 0 ELSE 1 END SEPARATOR '/') AS label_types
        FROM tb_real_shop_picking_report pr
        LEFT JOIN tb_real_shop_product_stock s ON pr.ne_syohin_syohin_code = s.ne_syohin_syohin_code
        LEFT JOIN tb_real_shop_information i ON s.daihyo_syohin_code = i.daihyo_syohin_code
        WHERE pr.status = :statusNone
        GROUP BY pr.picking_date, pr.number
      ) AS T
EOD;

    $params = [];
    $params[':statusNone'] = self::REPORT_STATUS_NONE;

    $rsm =  new ResultSetMapping();
    $rsm->addScalarResult('picking_date', 'picking_date', 'date');
    $rsm->addScalarResult('number', 'number', 'integer');
    $rsm->addScalarResult('account_name', 'account_name', 'string');
    $rsm->addScalarResult('product_count', 'product_count', 'integer');
    $rsm->addScalarResult('move_num', 'move_num', 'integer');
    $rsm->addScalarResult('label_types', 'label_types', 'string');

    $query = LimitableNativeQuery::createQuery($em, $rsm, $sqlSelect, $sqlBody);
    foreach($params as $k => $v) {
      $query->setParameter($k, $v);
    }

    $resultOrders = [];
    $defaultOrders = [
        'T.picking_date' => 'DESC'
      , 'T.number' => 'DESC'
    ];
    $query->setOrders(array_merge($resultOrders, $defaultOrders));

    /** @var \Knp\Component\Pager\Paginator $paginator */
    $paginator  = $this->getContainer()->get('knp_paginator');
    /** @var \Knp\Bundle\PaginatorBundle\Pagination\SlidingPagination $pagination */
    $pagination = $paginator->paginate(
        $query /* query NOT result */
      , $page
      , $limit
    );

    return $pagination;
  }


  /**
   * 一回分のピッキング情報全件取得
   * @param $date
   * @param $number
   * @return TbRealShopPickingReport[]
   */
  public function getReportDetail($date, $number)
  {
    $dbMain = $this->getConnection('main');

    $sql = <<<EOD
      SELECT
          pr.*
        , i.label_type
      FROM tb_real_shop_picking_report pr
      LEFT JOIN tb_real_shop_product_stock s ON pr.ne_syohin_syohin_code = s.ne_syohin_syohin_code
      LEFT JOIN tb_real_shop_information i ON s.daihyo_syohin_code = i.daihyo_syohin_code
      WHERE pr.picking_date = :pickingDate
        AND pr.number = :number
      ORDER BY pr.ne_syohin_syohin_code
EOD;
    $stmt = $dbMain->prepare($sql);
    $stmt->bindValue(':pickingDate', $date, \PDO::PARAM_STR);
    $stmt->bindValue(':number', $number, \PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * ラベル印刷情報 取得
   * @param $date
   * @param $number
   * @param string $type
   * @return TbRealShopPickingReport[]
   */
  public function getReportLabelList($date, $number, $type)
  {
    $dbMain = $this->getConnection('main');

    $sql = <<<EOD
      SELECT
          pr.ne_syohin_syohin_code
        , code.id AS product_code
        , pci.colname
        , pci.rowname
        , i.baika_tanka
        , pr.move_num
      FROM tb_real_shop_picking_report pr
      LEFT JOIN tb_productchoiceitems pci ON pr.ne_syohin_syohin_code = pci.ne_syohin_syohin_code
      LEFT JOIN tb_real_shop_information i ON pci.daihyo_syohin_code = i.daihyo_syohin_code
      LEFT JOIN tb_product_code code ON pr.ne_syohin_syohin_code = code.ne_syohin_syohin_code
      WHERE pr.picking_date = :pickingDate
        AND pr.number = :number
        AND i.label_type = :type
      ORDER BY pr.ne_syohin_syohin_code
EOD;
    $stmt = $dbMain->prepare($sql);
    $stmt->bindValue(':pickingDate', $date, \PDO::PARAM_STR);
    $stmt->bindValue(':number', $number, \PDO::PARAM_INT);
    $stmt->bindValue(':type', $type, \PDO::PARAM_STR);
    $stmt->execute();

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * ラベル印刷情報 取得（商品コード 指定）
   * @param string $syohinCode
   * @return array
   */
  public function getProductLabel($syohinCode)
  {
    $dbMain = $this->getConnection('main');

    $sql = <<<EOD
      SELECT
          pci.ne_syohin_syohin_code
        , code.id AS product_code
        , pci.colname
        , pci.rowname
        , i.baika_tanka
      FROM tb_productchoiceitems pci
      LEFT JOIN tb_real_shop_information i ON pci.daihyo_syohin_code = i.daihyo_syohin_code
      LEFT JOIN tb_product_code code ON pci.ne_syohin_syohin_code = code.ne_syohin_syohin_code
      WHERE pci.ne_syohin_syohin_code = :neSyohinSyohinCode
      ORDER BY pci.ne_syohin_syohin_code
EOD;
    $stmt = $dbMain->prepare($sql);
    $stmt->bindValue(':neSyohinSyohinCode', $syohinCode, \PDO::PARAM_STR);
    $stmt->execute();

    return $stmt->fetch(\PDO::FETCH_ASSOC);
  }

  /**
   * 入庫確定処理
   * @param $date
   * @param $number
   * @throws \Doctrine\DBAL\DBALException
   */
  public function submitImport($date, $number)
  {
    $dbMain = $this->getConnection('main');

    $sql = <<<EOD
      UPDATE
      tb_real_shop_picking_report pr
      SET pr.status = :statusImported
      WHERE pr.picking_date = :pickingDate
        AND pr.number = :number
EOD;
    $stmt = $dbMain->prepare($sql);
    $stmt->bindValue(':statusImported', self::REPORT_STATUS_IMPORTED);
    $stmt->bindValue(':pickingDate', $date, \PDO::PARAM_STR);
    $stmt->bindValue(':number', $number, \PDO::PARAM_INT);
    $stmt->execute();

    return;
  }


}