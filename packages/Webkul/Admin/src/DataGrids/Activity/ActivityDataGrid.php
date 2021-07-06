<?php

namespace Webkul\Admin\DataGrids\Activity;

use Carbon\Carbon;
use Webkul\UI\DataGrid\DataGrid;
use Illuminate\Support\Facades\DB;

class ActivityDataGrid extends DataGrid
{
    protected $users = [];
    protected $tabFilters = [];

    protected $redirectRow = [
        "id"    => "lead_id",
        "route" => "admin.leads.view",
    ];

    public function __construct()
    {
        $this->tabFilters = [
            [
                'type'      => 'pill',
                'key'       => 'type',
                'condition' => 'eq',
                'values'    => [
                    [
                        'name'      => trans('admin::app.leads.all'),
                        'isActive'  => true,
                        'key'       => 'all',
                    ], [
                        'name'      => trans('admin::app.leads.note'),
                        'isActive'  => false,
                        'key'       => 'note',
                    ], [
                        'name'      => trans('admin::app.leads.call'),
                        'isActive'  => false,
                        'key'       => 'call',
                    ], [
                        'name'      => trans('admin::app.leads.email'),
                        'isActive'  => false,
                        'key'       => 'email',
                    ], [
                        'name'      => trans('admin::app.leads.meeting'),
                        'isActive'  => false,
                        'key'       => 'meeting',
                    ]
                ]
            ], [
                'type'      => 'group',
                'key'       => 'duration',
                'condition' => 'eq',
                'values'    => [
                    [
                        'name'      => 'Yesterday',
                        'isActive'  => false,
                        'key'       => 'yesterday',
                    ], [
                        'name'      => 'Today',
                        'isActive'  => false,
                        'key'       => 'today',
                    ], [
                        'name'      => 'Tomorrow',
                        'isActive'  => false,
                        'key'       => 'tomorrow',
                    ], [
                        'name'      => 'This week',
                        'isActive'  => false,
                        'key'       => 'this_week',
                    ], [
                        'name'      => 'This month',
                        'isActive'  => true,
                        'key'       => 'this_month',
                    ], [
                        'name'      => 'Custom',
                        'isActive'  => false,
                        'key'       => 'custom',
                    ]
                ]
            ],
        ];

        $userRepository = app('\Webkul\User\Repositories\UserRepository');

        $users = $userRepository->all();

        foreach ($users as $user) {
            array_push($this->users, [
                'value' => $user['id'],
                'label' => $user['name'],
            ]);
        }

        parent::__construct();
    }

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('lead_activities')
                        ->select(
                            'lead_activities.*',
                            'leads.id as lead_id',
                            'users.id as assignee_id',
                            'users.name as assigned_to',
                            'leads.title as lead_title',
                            'persons.name as contact_person',
                            'persons.id as contact_person_id'
                        )
                        ->where(function ($query) {
                            if (($currentUser = auth()->guard('user')->user())->lead_view_permission == "individual") {
                                $query->where('lead_activities.user_id', $currentUser->id);
                            }
                        })
                        ->leftJoin('leads', 'lead_activities.lead_id', '=', 'leads.id')
                        ->leftJoin('users', 'lead_activities.user_id', '=', 'users.id')
                        ->leftJoin('persons', 'leads.person_id', '=', 'persons.id');

        $this->addFilter('id', 'lead_activities.id');
        $this->addFilter('assigned_to', 'users.name');
        $this->addFilter('user', 'lead_activities.user_id');

        $this->setQueryBuilder($queryBuilder);
    }

    public function addColumns()
    {
        $this->addColumn([
            'index'             => 'user',
            'label'             => trans('admin::app.datagrid.user'),
            'type'              => 'hidden',
            'sortable'          => true,
            'filterable_type'   => 'dropdown',
            'filterable_options' => $this->users,
        ]);

        $this->addColumn([
            'index'             => 'subject',
            'label'             => trans('admin::app.datagrid.subject'),
            'type'              => 'string',
            'closure'           => function ($row) {
                $route = urldecode(route('admin.leads.index', ['type' => 'table', 'id[eq]' => $row->lead_id]));

                return "<a href='" . $route . "'>" . $row->lead_title . "</a>";
            },
        ]);

        $this->addColumn([
            'index'       => 'comment',
            'label'       => trans('admin::app.datagrid.comment'),
            'type'        => 'string',
            'searchable'  => true,
        ]);

        $this->addColumn([
            'index'              => 'type',
            'label'              => trans('admin::app.datagrid.type'),
            'type'               => 'boolean',
        ]);

        $this->addColumn([
            'index'              => 'is_done',
            'label'              => trans('admin::app.datagrid.is_done'),
            'type'               => 'boolean',
            'filterable_type'    => 'dropdown',
            'filterable_options' => [[
                'value' => 0,
                'label' => __("admin::app.common.no"),
            ], [
                'value' => 1,
                'label' => __("admin::app.common.yes"),
            ]],
            'closure'            => function ($row) {
                if ($row->is_done) {
                    return '<span class="badge badge-round badge-success"></span>' . __("admin::app.common.yes");
                } else {
                    return '<span class="badge badge-round badge-danger"></span>' . __("admin::app.common.no");
                }

                // return "<span class='checkbox'><input type='checkbox'" . ($row->is_done ? "checked=checked" : "") . "disabled='disabled'><label for='checkbox' class='checkbox-view'></label></span>";
            },
        ]);

        $this->addColumn([
            'index'             => 'contact_person',
            'label'             => trans('admin::app.datagrid.contact_person'),
            'type'              => 'string',
            'closure'           => function ($row) {
                $route = urldecode(route('admin.contacts.persons.index', ['id[eq]' => $row->contact_person_id]));

                return "<a href='" . $route . "'>" . $row->contact_person . "</a>";
            },
        ]);

        $this->addColumn([
            'index'             => 'assigned_to',
            'label'             => trans('admin::app.datagrid.assigned_to'),
            'type'              => 'string',
            'closure'           => function ($row) {
                $route = urldecode(route('admin.settings.users.index', ['id[eq]' => $row->assignee_id]));

                return "<a href='" . $route . "'>" . $row->assigned_to . "</a>";
            },
        ]);

        $this->addColumn([
            'index'             => 'schedule_from',
            'label'             => trans('admin::app.datagrid.schedule_from'),
            'title'             => true,
            'type'              => 'string',
            'sortable'          => true,
            'filterable_type'   => 'date_range',
            'closure'           => function ($row) {
                return core()->formatDate($row->schedule_from);
            },
        ]);

        $this->addColumn([
            'index'             => 'schedule_to',
            'label'             => trans('admin::app.datagrid.schedule_to'),
            'title'             => true,
            'type'              => 'string',
            'sortable'          => true,
            'filterable_type'   => 'date_range',
            'closure'           => function ($row) {
                return core()->formatDate($row->schedule_to);
            },
        ]);

        $this->addColumn([
            'index'             => 'created_at',
            'label'             => trans('admin::app.datagrid.created_at'),
            'title'             => true,
            'type'              => 'string',
            'sortable'          => true,
            'filterable_type'   => 'date_range',
            'closure'           => function ($row) {
                return core()->formatDate($row->created_at);
            },
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'title'        => trans('ui::app.datagrid.delete'),
            'method'       => 'DELETE',
            'route'        => 'admin.activities.delete',
            'confirm_text' => trans('ui::app.datagrid.massaction.delete', ['resource' => 'user']),
            'icon'         => 'trash-icon',
        ]);
    }
}
