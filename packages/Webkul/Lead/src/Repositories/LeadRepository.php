<?php

namespace Webkul\Lead\Repositories;

use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Webkul\Core\Eloquent\Repository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;

class LeadRepository extends Repository
{
    /**
     * StageRepository object
     *
     * @var \Webkul\Lead\Repositories\StageRepository
     */
    protected $stageRepository;

    /**
     * PersonRepository object
     *
     * @var \Webkul\Contact\Repositories\PersonRepository
     */
    protected $personRepository;

    /**
     * ProductRepository object
     *
     * @var \Webkul\Lead\Repositories\ProductRepository
     */
    protected $productRepository;

    /**
     * AttributeValueRepository object
     *
     * @var \Webkul\Attribute\Repositories\AttributeValueRepository
     */
    protected $attributeValueRepository;

    /**
     * Create a new repository instance.
     *
     * @param  \Webkul\Lead\Repositories\StageRepository  $stageRepository
     * @param  \Webkul\Contact\Repositories\PersonRepository  $personRepository
     * @param  \Webkul\Lead\Repositories\ProductRepository  $productRepository
     * @param  \Webkul\Attribute\Repositories\AttributeValueRepository  $attributeValueRepository
     * @param  \Illuminate\Container\Container  $container
     * @return void
     */
    public function __construct(
        StageRepository $stageRepository,
        PersonRepository $personRepository,
        ProductRepository $productRepository,
        AttributeValueRepository $attributeValueRepository,
        Container $container
    ) {
        $this->stageRepository = $stageRepository;

        $this->personRepository = $personRepository;

        $this->productRepository = $productRepository;

        $this->attributeValueRepository = $attributeValueRepository;

        parent::__construct($container);
    }

    /**
     * Specify Model class name
     *
     * @return mixed
     */
    function model()
    {
        return 'Webkul\Lead\Contracts\Lead';
    }

    /**
     * @param  integer  $pipelineId
     * @param  string  $term
     * @param  string  $createdAtRange
     * @return \Webkul\Lead\Contracts\Lead
     */
    public function getLeads($pipelineId, $term, $createdAtRange)
    {
        return $this
                ->select(
                    'leads.id as id',
                    'leads.created_at as created_at',
                    'title',
                    'lead_value',
                    'persons.name as person_name',
                    'lead_pipelines.id as lead_pipeline_id',
                    'lead_pipeline_stages.name as status',
                    'lead_pipeline_stages.id as lead_pipeline_stage_id'
                )
                ->addSelect(\DB::raw('DATEDIFF(leads.created_at + INTERVAL lead_pipelines.rotten_days DAY, now()) as rotten_days'))
                ->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                ->leftJoin('lead_pipelines', 'leads.lead_pipeline_id', '=', 'lead_pipelines.id')
                ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
                ->where("title", 'like', "%$term%")
                ->where("leads.lead_pipeline_id", $pipelineId)
                ->when($createdAtRange, function($query) use ($createdAtRange) {
                    return $query->whereBetween('leads.created_at', $createdAtRange);
                })
                ->where(function ($query) {
                    $currentUser = auth()->guard('user')->user();

                    if ($currentUser->view_permission != 'global') {
                        if ($currentUser->view_permission == 'group') {
                            $query->whereIn('leads.user_id', app('\Webkul\User\Repositories\UserRepository')->getCurrentUserGroupsUserIds());
                        } else {
                            $query->where('leads.user_id', $currentUser->id);
                        }
                    }
                })
                ->get();
    }

    /**
     * @param array $data
     * @return \Webkul\Lead\Contracts\Lead
     */
    public function create(array $data)
    {
        if (isset($data['person']['id'])) {
            $person = $this->personRepository->update(array_merge($data['person'], [
                'entity_type' => 'persons',
            ]), $data['person']['id']);
        } else {
            $person = $this->personRepository->create(array_merge($data['person'], [
                'entity_type' => 'persons',
            ]));
        }

        $lead = parent::create(array_merge([
            'person_id'        => $person->id,
            'lead_pipeline_id' => 1,
            'lead_pipeline_stage_id'    => $data['lead_pipeline_stage_id'] ?? 1,
        ], $data));

        $this->attributeValueRepository->save($data, $lead->id);

        if (isset($data['products'])) {
            foreach ($data['products'] as $product) {
                $this->productRepository->create(array_merge($product, [
                    'lead_id' => $lead->id,
                    'amount'  => $product['price'] * $product['quantity'],
                ]));
            }
        }

        return $lead;
    }

    /**
     * @param array  $data
     * @param int    $id
     * @param string $attribute
     * @return \Webkul\Lead\Contracts\Lead
     */
    public function update(array $data, $id, $attribute = "id")
    {
        if (isset($data['person'])) {
            if (isset($data['person']['id'])) {
                $person = $this->personRepository->update(array_merge($data['person'], [
                    'entity_type' => 'persons',
                ]), $data['person']['id']);
            } else {
                $person = $this->personRepository->create(array_merge($data['person'], [
                    'entity_type' => 'persons',
                ]));
            }

            $data = array_merge([
                'person_id' => $person->id,
            ], $data);
        }

        if (isset($data['lead_pipeline_stage_id'])) {
            $stage = $this->stageRepository->find($data['lead_pipeline_stage_id']);

            if (in_array($stage->code, ['won', 'lost'])) {
                $data['closed_at'] = $data['closed_at'] ?? Carbon::now();
            } else {
                $data['closed_at'] = null;
            }
        }

        $lead = parent::update($data, $id);

        $this->attributeValueRepository->save($data, $id);

        if (! isset($data['_method'])) {
            return $lead;
        }

        $previousProductIds = $lead->products()->pluck('id');

        if (isset($data['products'])) {
            foreach ($data['products'] as $productId => $productInputs) {
                if (Str::contains($productId, 'product_')) {
                    $this->productRepository->create(array_merge([
                        'lead_id' => $lead->id,
                    ], $productInputs));
                } else {
                    if (is_numeric($index = $previousProductIds->search($productId))) {
                        $previousProductIds->forget($index);
                    }

                    $this->productRepository->update($productInputs, $productId);
                }
            }
        }

        foreach ($previousProductIds as $productId) {
            $this->productRepository->delete($productId);
        }

        return $lead;
    }

    /**
     * Retrieves lead count based on lead stage name
     *
     * @return number
     */
    public function getLeadsCount($leadStage, $startDate, $endDate)
    {
        $query = $this
                ->whereBetween('leads.created_at', [$startDate, $endDate])
                ->where(function ($query) {
                    if (($currentUser = auth()->guard('user')->user())->view_permission == "individual") {
                        $query->where('leads.user_id', $currentUser->id);
                    }
                });

        if ($leadStage != "all") {
            $query
                ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
                ->where('lead_pipeline_stages.name', $leadStage);
        }

        return $query
                ->get()
                ->count();
    }

    /**
     * Retrieves user details by lead id
     *
     * @return \Webkul\Lead\Contracts\Lead
     */
    public function getUserByLeadId($leadId)
    {
        return $this->select('users.id', 'users.email', 'users.name')
                ->where('leads.id', $leadId)
                ->leftJoin('users', 'leads.user_id', 'users.id')
                ->first();
    }
}