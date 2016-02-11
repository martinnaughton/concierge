<?php

namespace Timegridio\Concierge\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use McCool\LaravelAutoPresenter\HasPresenter;
use Timegridio\Concierge\Presenters\AppointmentPresenter;

/**
 * An Appointment can be understood as a reservation of a given Service,
 * provided by a given Business, targeted to a Contact, which will take place
 * on a determined Date and Time, and might have a duration and or comments.
 *
 * The Appointment can be issued by the Contact's User or by the Business owner.
 */
class Appointment extends EloquentModel implements HasPresenter
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['issuer_id', 'contact_id', 'business_id',
        'service_id', 'start_at', 'finish_at', 'duration', 'comments', ];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id', 'hash', 'status', 'vacancy_id'];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['start_at', 'finish_at'];

    /**
     * Appointment Hard Status Constants.
     */
    const STATUS_RESERVED = 'R';
    const STATUS_CONFIRMED = 'C';
    const STATUS_ANNULATED = 'A';
    const STATUS_SERVED = 'S';

    ///////////////
    // PRESENTER //
    ///////////////

    /**
     * Get Presenter Class.
     *
     * @return App\Presenters\AppointmentPresenter
     */
    public function getPresenterClass()
    {
        return AppointmentPresenter::class;
    }

    /**
     * Generate hash and save the model to the database.
     *
     * @param array $options
     *
     * @return bool
     */
    public function save(array $options = [])
    {
        $this->doHash();

        return parent::save($options);
    }

    ///////////////////
    // Relationships //
    ///////////////////

    /**
     * Get the issuer (the User that generated the Appointment).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function issuer()
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    /**
     * Get the target Contact (for whom is reserved the Appointment).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the holding Business (that has taken the reservation).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Get the reserved Service.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the Vacancy (that justifies the availability of resources for the
     * Appointment generation).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vacancy()
    {
        return $this->belongsTo(Vacancy::class);
    }

    ///////////
    // Other //
    ///////////

    /**
     * Get the User through Contact.
     *
     * @return User
     */
    public function user()
    {
        return $this->contact->user;
    }

    /**
     * Determine if the new Appointment will hash-crash with another existing
     * Appointment.
     *
     * @return bool
     */
    public function duplicates()
    {
        return !self::where('hash', $this->hash)->get()->isEmpty();
    }

    ///////////////
    // Accessors //
    ///////////////

    /**
     * Get Hash.
     *
     * @return string
     */
    public function getHashAttribute()
    {
        return isset($this->attributes['hash'])
            ? $this->attributes['hash']
            : $this->doHash();
    }

    /**
     * Get Finish At:
     * Calculates the start_at time plus duration in minutes.
     *
     * @return Carbon
     */
    public function getFinishAtAttribute()
    {
        if (array_get($this->attributes, 'finish_at') !== null) {
            return Carbon::parse($this->attributes['finish_at']);
        }

        if (is_numeric($this->duration)) {
            return $this->start_at->addMinutes($this->duration);
        }

        return $this->start_at;
    }

    /**
     * Get TimeZone (from the Business).
     *
     * @return string
     */
    public function getTZAttribute()
    {
        return $this->business->timezone;
    }

    /**
     * Get the human readable status name.
     *
     * @return string
     */
    public function getStatusLabelAttribute()
    {
        $labels = [
            Self::STATUS_RESERVED  => 'reserved',
            Self::STATUS_CONFIRMED => 'confirmed',
            Self::STATUS_ANNULATED => 'annulated',
            Self::STATUS_SERVED    => 'served',
            ];

        return array_key_exists($this->status, $labels)
            ? $labels[$this->status]
            : '';
    }

    /**
     * Get the date of the Appointment.
     *
     * @return string
     */
    public function getDateAttribute()
    {
        return $this->start_at
                    ->timezone($this->business->timezone)
                    ->toDateString();
    }

    //////////////
    // Mutators //
    //////////////

    /**
     * Generate Appointment hash.
     *
     * @return string
     */
    public function doHash()
    {
        return $this->attributes['hash'] = md5(
            $this->start_at.'/'.
            $this->contact_id.'/'.
            $this->business_id.'/'.
            $this->service_id
        );
    }

    /**
     * Set start at.
     *
     * @param Carbon $datetime
     */
    public function setStartAtAttribute(Carbon $datetime)
    {
        $this->attributes['start_at'] = $datetime;
    }

    /**
     * Set finish_at attribute.
     *
     * @param Carbon $datetime
     */
    public function setFinishAtAttribute(Carbon $datetime)
    {
        $this->attributes['finish_at'] = $datetime;
    }

    /**
     * Set Comments.
     *
     * @param string $comments
     */
    public function setCommentsAttribute($comments)
    {
        $this->attributes['comments'] = trim($comments) ?: null;
    }

    /////////////////
    // HARD STATUS //
    /////////////////

    /**
     * Determine if is Reserved.
     *
     * @return bool
     */
    public function isReserved()
    {
        return $this->status == Self::STATUS_RESERVED;
    }

    ///////////////////////////
    // Calculated attributes //
    ///////////////////////////

    /**
     * Appointment Status Workflow.
     *
     * Hard Status: Those concrete values stored in DB
     * Soft Status: Those values calculated from stored values in DB
     *
     * Suggested transitions (Binding is not mandatory)
     *     Reserved -> Confirmed -> Served
     *     Reserved -> Served
     *     Reserved -> Annulated
     *     Reserved -> Confirmed -> Annulated
     *
     * Soft Status
     *     (Active)   [ Reserved  | Confirmed ]
     *     (InActive) [ Annulated | Served    ]
     */

    /**
     * Determine if is Active.
     *
     * @return bool
     */
    public function isActive()
    {
        return
            $this->status == Self::STATUS_CONFIRMED ||
            $this->status == Self::STATUS_RESERVED;
    }

    /**
     * Determine if is Pending.
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->isActive() && $this->isFuture();
    }

    /**
     * Determine if is Future.
     *
     * @return bool
     */
    public function isFuture()
    {
        return !$this->isDue();
    }

    /**
     * Determine if is due.
     *
     * @return bool
     */
    public function isDue()
    {
        return $this->start_at->isPast();
    }

    ////////////
    // Scopes //
    ////////////

    /////////////////////////
    // Hard Status Scoping //
    /////////////////////////

    /**
     * Scope to Unarchived Appointments.
     *
     * @param Illuminate\Database\Query $query
     *
     * @return Illuminate\Database\Query
     */
    public function scopeUnarchived($query)
    {
        return $query
            ->where(function($query) {
                $query->whereIn('status', [Self::STATUS_RESERVED, Self::STATUS_CONFIRMED])
                    ->where('start_at', '<=', Carbon::parse('today midnight')->timezone('UTC'))
                    ->orWhere(function($query) {
                        $query->where('start_at', '>=', Carbon::parse('today midnight')->timezone('UTC'));
                    });
            });
    }

    /**
     * Scope to Served Appointments.
     *
     * @param Illuminate\Database\Query $query
     *
     * @return Illuminate\Database\Query
     */
    public function scopeServed($query)
    {
        return $query->where('status', '=', Self::STATUS_SERVED);
    }

    /**
     * Scope to Annulated Appointments.
     *
     * @param Illuminate\Database\Query $query
     *
     * @return Illuminate\Database\Query
     */
    public function scopeAnnulated($query)
    {
        return $query->where('status', '=', Self::STATUS_ANNULATED);
    }

    /////////////////////////
    // Soft Status Scoping //
    /////////////////////////

    /**
     * Scope to not Served Appointments.
     *
     * @param Illuminate\Database\Query $query
     *
     * @return Illuminate\Database\Query
     */
    public function scopeUnServed($query)
    {
        return $query->where('status', '<>', Self::STATUS_SERVED);
    }

    /**
     * Scope to Active Appointments.
     *
     * @param Illuminate\Database\Query $query
     *
     * @return Illuminate\Database\Query
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [Self::STATUS_RESERVED, Self::STATUS_CONFIRMED]);
    }

    /**
     * Scope of Business.
     *
     * @param Illuminate\Database\Query $query
     * @param int                       $businessId
     *
     * @return Illuminate\Database\Query
     */
    public function scopeOfBusiness($query, $businessId)
    {
        return $query->where('business_id', '=', $businessId);
    }

    /**
     * Scope of date.
     *
     * @param Illuminate\Database\Query $query
     * @param Carbon                    $date
     *
     * @return Illuminate\Database\Query
     */
    public function scopeOfDate($query, Carbon $date)
    {
        return $query->whereRaw('date(`start_at`) = ?', [$date->timezone('UTC')->toDateString()]);
    }

    /**
     * Scope only future appointments.
     *
     * @param Illuminate\Database\Query $query
     *
     * @return Illuminate\Database\Query
     */
    public function scopeFuture($query)
    {
        $todayMidnight = Carbon::parse('today midnight')->timezone('UTC');

        return $query->where('start_at', '>=', $todayMidnight);
    }

    /**
     * Scope only till date.
     *
     * @param Illuminate\Database\Query $query
     * @param Carbon                    $date
     *
     * @return Illuminate\Database\Query
     */
    public function scopeTillDate($query, Carbon $date)
    {
        return $query->where('start_at', '<=', $date->timezone('UTC'));
    }

    /**
     * Between Dates.
     *
     * @param Illuminate\Database\Query $query
     * @param Carbon                    $startAt
     * @param Carbon                    $finishAt
     *
     * @return Illuminate\Database\Query
     */
    public function scopeAffectingInterval($query, Carbon $startAt, Carbon $finishAt)
    {
        return $query
            ->where(function ($query) use ($startAt, $finishAt) {

                $query->where(function ($query) use ($startAt, $finishAt) {
                    $query->where('finish_at', '>=', $finishAt->timezone('UTC'))
                            ->where('start_at', '<=', $startAt->timezone('UTC'));
                })
                ->orWhere(function ($query) use ($startAt, $finishAt) {
                    $query->where('finish_at', '<', $finishAt->timezone('UTC'))
                            ->where('finish_at', '>', $startAt->timezone('UTC'));
                })
                ->orWhere(function ($query) use ($startAt, $finishAt) {
                    $query->where('start_at', '>', $startAt->timezone('UTC'))
                            ->where('start_at', '<', $finishAt->timezone('UTC'));
                })
                ->orWhere(function ($query) use ($startAt, $finishAt) {
                    $query->where('start_at', '>', $startAt->timezone('UTC'))
                            ->where('finish_at', '<', $finishAt->timezone('UTC'));
                });

            });
    }

    /**
     * Determine if the Serve action can be performed.
     *
     * @return bool
     */
    public function isServeable()
    {
        return $this->isActive() && $this->isDue();
    }

    /**
     * Determine if the Confirm action can be performed.
     *
     * @return bool
     */
    public function isConfirmable()
    {
        return $this->status == self::STATUS_RESERVED && $this->isFuture();
    }

    /**
     * Determine if the Annulate action can be performed.
     *
     * @return bool
     */
    public function isAnnulable()
    {
        return $this->isActive();
    }

    /////////////////////////
    // Hard Status Actions //
    /////////////////////////

    /**
     * Check and perform Confirm action.
     *
     * @return $this
     */
    public function doReserve()
    {
        if ($this->status === null) {
            $this->status = self::STATUS_RESERVED;
        }

        return $this;
    }

    /**
     * Check and perform Confirm action.
     *
     * @return $this
     */
    public function doConfirm()
    {
        if ($this->isConfirmable()) {
            $this->status = self::STATUS_CONFIRMED;

            $this->save();
        }

        return $this;
    }

    /**
     * Check and perform Annulate action.
     *
     * @return $this
     */
    public function doAnnulate()
    {
        if ($this->isAnnulable()) {
            $this->status = self::STATUS_ANNULATED;

            $this->save();
        }

        return $this;
    }

    /**
     * Check and perform Serve action.
     *
     * @return $this
     */
    public function doServe()
    {
        if ($this->isServeable()) {
            $this->status = self::STATUS_SERVED;

            $this->save();
        }

        return $this;
    }
}
