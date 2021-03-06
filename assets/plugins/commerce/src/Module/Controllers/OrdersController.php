<?php

namespace Commerce\Module\Controllers;

class OrdersController extends Controller implements \Commerce\Module\Interfaces\Controller
{
    private $lang;

    public function __construct($modx, $module)
    {
        parent::__construct($modx, $module);
        $this->lang = $this->modx->commerce->getUserLanguage('order');
    }

    public function registerRoutes()
    {
        return [
            'index'         => 'index',
            'edit'          => 'show',
            'change-status' => 'changeStatus',
        ];
    }

    public function index()
    {
        $columns = $this->getOrdersListColumns();

        $config = [
            'orderBy'         => 'created_at DESC',
            'display'         => 10,
            'paginate'        => 'pages',
            'TplWrapPaginate' => '@CODE:<ul class="[+class+]">[+wrap+]</ul>',
            'TplCurrentPage'  => '@CODE:<li class="page-item active"><span class="page-link">[+num+]</span></li>',
            'TplPage'         => '@CODE:<li class="page-item"><a href="[+link+]" class="page-link page" data-page="[+num+]">[+num+]</a></li>',
            'TplNextP'        => '@CODE:',
            'TplPrevP'        => '@CODE:',
        ];

        $this->modx->invokeEvent('OnManagerBeforeOrdersListRender', [
            'config'  => &$config,
            'columns' => &$columns,
        ]);

        $columns   = $this->sortFields($columns);
        $config    = $this->injectPrepare($config, $columns);
        $ordersUrl = $this->module->makeUrl('orders');

        $list = $this->modx->runSnippet('DocLister', array_merge($config, [
            'controller'      => 'onetable',
            'table'           => 'commerce_orders',
            'idType'          => 'documents',
            'id'              => 'list',
            'showParent'      => '-1',
            'api'             => 1,
            'ignoreEmpty'     => 1,
            'makePaginateUrl' => function($link, $modx, $DL, $pager) use ($ordersUrl) {
                return $ordersUrl;
            },
        ]));

        $list = json_decode($list, true);

        return $this->view->render('orders_list.tpl', [
            'columns' => $columns,
            'orders'  => $list,
            'custom'  => $this->module->invokeTemplateEvent('OnManagerOrdersListRender'),
        ]);
    }

    public function show()
    {
        $order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        $processor = $this->modx->commerce->loadProcessor();
        $order = $processor->loadOrder($order_id);

        if (empty($order)) {
            $this->module->sendRedirect('orders', ['error' => $this->lang['module.error.order_not_found']]);
        }

        $config  = [
            'imageField' => 'tv.image',
            'tvList'     => 'image',
        ];

        $groups     = $this->getOrderGroups();
        $columns    = $this->getOrderCartColumns();
        $subcolumns = $this->getOrderSubtotalsColumns();

        $this->modx->invokeEvent('OnManagerBeforeOrderRender', [
            'groups'     => &$groups,
            'config'     => &$config,
            'columns'    => &$columns,
            'subcolumns' => &$subcolumns,
        ]);

        foreach ($groups as $group_id => &$group) {
            $group['fields'] = $this->sortFields($group['fields']);

            $values = $this->processFields($group['fields'], ['data' => $order]);

            array_walk($group['fields'], function(&$item, $key) use ($values) {
                $item['value'] = $values[$key];
            });
        }

        unset($group);

        $columns = $this->sortFields($columns);
        $config  = $this->injectPrepare($config, $columns);

        $cart = $processor->getCart();
        $cartData = $this->modx->runSnippet('DocLister', array_merge($config, [
            'controller' => 'Cart',
            'dir'        => 'assets/plugins/commerce/src/Controllers/',
            'sortType'   => 'doclist',
            'idType'     => 'documents',
            'documents'  => array_column($cart->getItems(), 'id'),
            'instance'   => 'order',
            'cart'       => $cart,
            'tree'       => 0,
            'api'        => 1,
        ]));

        $subcolumns = $this->sortFields($subcolumns);
        $subtotals  = [];
        $cart->getSubtotals($subtotals, $total);

        foreach ($subtotals as $i => $row) {
            $subtotals[$i]['cells'] = $this->processFields($subcolumns, ['data' => $row]);
        }

        $query   = $this->modx->db->select('*', $this->modx->getFullTablename('commerce_order_history'), "`order_id` = '" . $order['id'] . "'", 'created_at DESC');
        $history = $this->modx->db->makeArray($query);

        $query = $this->modx->db->select('*', $this->modx->getFullTablename('user_attributes'), "`internalKey` IN (" . implode(',', array_column($history, 'user_id')) . ")");
        $users = [];

        while ($row = $this->modx->db->getRow($query)) {
            $users[$row['internalKey']] = $row['fullname'];
        }

        return $this->view->render('order.tpl', [
            'order'      => $order,
            'groups'     => $groups,
            'cartData'   => json_decode($cartData, true),
            'columns'    => $columns,
            'statuses'   => $this->getStatuses(),
            'subcolumns' => $subcolumns,
            'subtotals'  => $subtotals,
            'history'    => $history,
            'users'      => $users,
            'custom'     => $this->module->invokeTemplateEvent('OnManagerOrderRender'),
        ]);
    }

    public function changeStatus()
    {
        $data = array_merge($_POST, $_GET);

        $result = $this->modx->commerce->validate($data, [
            'order_id' => [
                'numeric' => 'status_id should be numeric',
            ],
            'status_id' => [
                'numeric' => 'status_id should be numeric',
            ],
            '!description' => [
                'string' => 'description should be string',
            ],
        ]);

        if (is_array($result)) {
            $this->module->sendRedirectBack(['validation_errors' => $result]);
        }

        $processor = $this->modx->commerce->loadProcessor();
        $order = $processor->loadOrder($data['order_id']);

        if (empty($order)) {
            $this->module->sendRedirectBack(['error' => $this->lang['module.error.order_not_found']]);
        }

        $processor->changeStatus($order['id'], $data['status_id'], !empty($data['description']) ? $data['description'] : '', !empty($data['notify']));

        $this->module->sendRedirectBack(['success' => $this->lang['module.status_changed']]);
    }

    private function getStatuses()
    {
        if (is_null($this->statuses)) {
            $query = $this->modx->db->select('id, title', $this->modx->getFullTablename('commerce_order_statuses'));
            $this->statuses = [];

            while ($row = $this->modx->db->getRow($query)) {
                $this->statuses[$row['id']] = $row['title'];
            }
        }

        return $this->statuses;
    }

    private function getOrdersListColumns()
    {
        $statuses = $this->getStatuses();
        $defaultCurrency = ci()->currency->getDefaultCurrencyCode();

        return [
            'id' => [
                'title'   => '#',
                'content' => 'id',
                'sort'    => 0,
                'style'   => 'width: 1%; text-align: center;',
            ],
            'date' => [
                'title'   => $this->lang['order.created_at'],
                'content' => function($data, $DL, $eDL) {
                    return (new \DateTime($data['created_at']))->format('d.m.Y H:i:s');
                },
                'sort' => 10,
            ],
            'name' => [
                'title'   => $this->lang['order.name_field'],
                'content' => 'name',
                'sort'    => 20,
            ],
            'phone' => [
                'title'   => $this->lang['order.phone_field'],
                'content' => 'phone',
                'sort'    => 30,
                'style'   => 'white-space: nowrap;',
            ],
            'email' => [
                'title'   => $this->lang['order.email_field'],
                'content' => function($data, $DL, $eDL) {
                    if (!empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                        return '<a href="mailto:' . $data['email'] . '">' . $data['email'] . '</a>';
                    }

                    return '';
                },
                'sort'    => 40,
                'style'   => 'white-space: nowrap;',
            ],
            'amount' => [
                'title'   => $this->lang['order.amount_title'],
                'content' => function($data, $DL, $eDL) use ($defaultCurrency) {
                    $currency = ci()->currency;
                    $out = $currency->format($data['amount'], $data['currency']);

                    if ($data['currency'] != $defaultCurrency) {
                        $out .= '<br>(' . $currency->formatWithDefault($data['amount'], $data['currency']) . ')';
                    }

                    return $out;
                },
                'style'   => 'text-align: right;',
                'sort'    => 50,
                'style'   => 'white-space: nowrap; text-align: right;',
            ],
            'delivery' => [
                'title' => $this->lang['order.delivery_title'],
                'content' => function($data, $DL, $eDL) {
                    return !empty($data['fields']['delivery_method_title']) ? $data['fields']['delivery_method_title'] : '';
                },
                'sort' => 60,
            ],
            'payment' => [
                'title' => $this->lang['order.payment_title'],
                'content' => function($data, $DL, $eDL) {
                    return !empty($data['fields']['payment_method_title']) ? $data['fields']['payment_method_title'] : '';
                },
                'sort' => 70,
            ],
            'status' => [
                'title' => $this->lang['order.status_title'],
                'content' => function($data, $DL, $eDL) use ($statuses) {
                    $out = '';

                    foreach ($statuses as $id => $title) {
                        $out .= '<option value="' . $id . '"' . ($id == $data['status_id'] ? ' selected' : '') . '>' . $title . '</option>';
                    }

                    return '<select name="status_id" onchange="location = \'' . $this->module->makeUrl('orders/change-status', 'order_id=' . $data['id'] . '&status_id=') . '\' + jQuery(this).val();">' . $out . '</select>';
                },
                'sort' => 80,
            ],
        ];
    }

    private function getOrderGroups()
    {
        $statuses = $this->getStatuses();
        $defaultCurrency = ci()->currency->getDefaultCurrencyCode();

        return [
            'order_info' => [
                'title' => $this->lang['order.order_info'],
                'width' => '33.333%',
                'fields' => [
                    'id' => [
                        'title'   => $this->lang['order.order_id'],
                        'content' => function($data) {
                            return '<strong>#' . $data['id'] . '</strong>';
                        },
                        'sort' => 10,
                    ],
                    'date' => [
                        'title'   => $this->lang['order.created_at'],
                        'content' => function($data) {
                            return (new \DateTime($data['created_at']))->format('d.m.Y H:i:s');
                        },
                        'sort' => 20,
                    ],
                    'status' => [
                        'title'   => $this->lang['order.status_title'],
                        'content' => function ($data) use ($statuses) {
                            return isset($statuses[$data['status_id']]) ? $statuses[$data['status_id']] : '';
                        },
                        'sort' => 30,
                    ],
                ],
            ],
            'contact' => [
                'title' => $this->lang['order.contact_group_title'],
                'width' => '33.333%',
                'fields' => [
                    'name' => [
                        'title'   => $this->lang['order.name_field'],
                        'content' => 'name',
                        'sort'    => 10,
                    ],
                    'phone' => [
                        'title'   => $this->lang['order.phone_field'],
                        'content' => 'phone',
                        'sort'    => 20,
                    ],
                    'email' => [
                        'title'   => $this->lang['order.email_field'],
                        'content' => function($data) {
                            if (!empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                                return '<a href="mailto:' . $data['email'] . '">' . $data['email'] . '</a>';
                            }

                            return '';
                        },
                        'sort' => 30,
                    ],
                ],
            ],
            'payment_delivery' => [
                'title' => $this->lang['order.payment_delivery_group_title'],
                'width' => '33.333%',
                'fields' => [
                    'amount' => [
                        'title'   => $this->lang['order.to_pay_title'],
                        'content' => function($data) use ($defaultCurrency) {
                            $currency = ci()->currency;
                            $out = $currency->format($data['amount'], $data['currency']);

                            if ($data['currency'] != $defaultCurrency) {
                                $out .= '<br>(' . $currency->formatWithDefault($data['amount'], $data['currency']) . ')';
                            }

                            return '<strong>' . $out . '</strong>';
                        },
                        'sort' => 10,
                    ],
                    'delivery' => [
                        'title' => $this->lang['order.delivery_title'],
                        'content' => function($data) {
                            return !empty($data['fields']['delivery_method_title']) ? $data['fields']['delivery_method_title'] : '';
                        },
                        'sort' => 20,
                    ],
                    'payment' => [
                        'title' => $this->lang['order.payment_title'],
                        'content' => function($data) {
                            return !empty($data['fields']['payment_method_title']) ? $data['fields']['payment_method_title'] : '';
                        },
                        'sort' => 30,
                    ],
                ],
            ],
        ];
    }

    private function getOrderCartColumns()
    {
        $lang = ci()->commerce->getUserLanguage('cart');
        $order = ci()->commerce->loadProcessor()->getOrder();
        $defaultCurrency = ci()->currency->getDefaultCurrencyCode();

        return [
            'position' => [
                'title'   => '#',
                'content' => 'iteration',
                'style'   => 'width: 1%;',
                'sort'    => 10,
            ],
            'image' => [
                'title' => $lang['cart.image'],
                'content' => function($data, $DL, $eDL) {
                    $imageField = $DL->getCFGDef('imageField', 'image');

                    if (!empty($data[$imageField])) {
                        $image = $this->modx->getConfig('site_url') . $this->modx->runSnippet('phpthumb', [
                            'input' => $data[$imageField],
                            'options' => 'w=80,h=80,f=jpg,bg=FFFFFF,far=C'
                        ]);

                        return '<img src="' . $image . '" alt="">';
                    }

                    return '';
                },
                'sort' => 20,
            ],
            'title' => [
                'title'   => $lang['cart.item_title'],
                'content' => 'title',
                'sort'    => 30,
            ],
            'options' => [
                'title' => $lang['cart.item_options'],
                'content' => function($data, $DL, $eDL) {
                    if (!empty($data['options'])) {
                        return '<pre>' . json_encode($data['options'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</pre>';
                    }
                    return '';
                },
                'sort' => 40,
            ],
            'count' => [
                'title'   => $lang['cart.count'],
                'content' => 'count',
                'style'   => 'text-align: center;',
                'sort'    => 50,
            ],
            'price' => [
                'title'   => $lang['cart.item_price'],
                'content' => function($data, $DL, $eDL) use ($order, $defaultCurrency) {
                    $currency = ci()->currency;
                    $out = $currency->format($data['price'], $order['currency']);

                    if ($order['currency'] != $defaultCurrency) {
                        $out .= '<br>(' . $currency->formatWithDefault($data['price'], $order['currency']) . ')';
                    }

                    return $out;
                },
                'style' => 'text-align: right; white-space: nowrap;',
                'sort' => 60,
            ],
            'summary' => [
                'title'   => $lang['cart.item_summary'],
                'content' => function($data, $DL, $eDL) use ($order, $defaultCurrency) {
                    $currency = ci()->currency;
                    $out = $currency->format($data['total'], $order['currency']);

                    if ($order['currency'] != $defaultCurrency) {
                        $out .= '<br>(' . $currency->formatWithDefault($data['total'], $order['currency']) . ')';
                    }

                    return $out;
                },
                'style' => 'text-align: right; white-space: nowrap;',
                'sort' => 70,
            ],
        ];
    }

    private function getOrderSubtotalsColumns()
    {
        $commerce = ci()->commerce;
        $currency = ci()->currency;
        $lang  = $commerce->getUserLanguage('cart');
        $order = $commerce->loadProcessor()->getOrder();
        $defaultCurrency = $currency->getDefaultCurrencyCode();

        return [
            'title' => [
                'title'   => $lang['cart.item_title'],
                'content' => 'title',
                'sort'    => 10,
            ],
            'price' => [
                'title'   => $lang['cart.item_price'],
                'content' => function($data) use ($order, $currency, $defaultCurrency) {
                    $currency = ci()->currency;
                    $out = $currency->format($data['price'], $order['currency']);

                    if ($order['currency'] != $defaultCurrency) {
                        $out .= '<br>(' . $currency->formatWithDefault($data['price'], $order['currency']) . ')';
                    }

                    return $out;
                },
                'style' => 'text-align: right; white-space: nowrap;',
                'sort' => 20,
            ],
        ];
    }
}
