
Модель которая помогает управлять тизерами для рекламных сетей


<?php
namespace adnet\teaser\db;

use adnet\campaign\db\CampaignRecord;
use adnet\campaign\db\TeaserConnectionRecord;
use adnet\cdn\selector\SelectorAbstract;
use adnet\common\db\CommonRecord;
use adnet\service\user\agent\Browser;
use adnet\service\user\agent\Platform;
use adnet\teaser\Image;
use adnet\behaviors\ResolveSegmentationBehavior;
use Yii;
use yii\base\Exception;
use yii\db\Expression;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\web\UploadedFile;


/**
 * Class TeaserRecord
 * @package adnet\teaser\db
 *
 * @property integer $id
 * @property string $title
 * @property string $group
 * @property string $body
 * @property string $teaser_type // одно из teaser/Image::TYPE_*
 * @property bool $is_deleted
 * @property bool $is_adult
 * @property bool $is_download
 * @property string $platforms_json
 * @property string $browsers_json
 * @property string $target_gender
 *
 * @property integer view_count_24h
 * @property integer click_count_24h
 *
 * @property integer view_count_month
 * @property integer click_count_month
 *
 * @property integer view_count_alltime
 * @property integer click_count_alltime
 *
 * @property double $ctr_24h
 * @property double $ctr_month
 * @property double $ctr_alltime
 *
 * @property double $reward_24h
 * @property double $reward_possible_24h
 * @property double $reward_rejected_24h
 * @property double $reward_expected_24h
 * @property double $reward_confirm_rate_24h
 *
 * @property double $reward_month
 * @property double $reward_possible_month
 * @property double $reward_rejected_month
 * @property double $reward_expected_month
 * @property double $reward_confirm_rate_month
 *
 * @property double $reward_alltime
 * @property double $reward_possible_alltime
 * @property double $reward_rejected_alltime
 * @property double $reward_expected_alltime
 * @property double $reward_confirm_rate_alltime
 *
 * @property double $epc_24h
 * @property double $epc_month
 * @property double $epc_alltime
 *
 * @property integer $last_time_insert_24h последнее время показа елемента за 24 часа
 * @property integer $last_time_insert_month последнее время показа елемента за месяц
 * @property integer $last_time_insert_alltime последнее время показа елемента за все время
 *
 * @property-read Image $image
 * @property TeaserConnectionRecord[] $teaserConnections
 *
 * @property integer $statistics_sync_at когда была проведена синхронизация статистики
 *
 * @property string $targetGenderReplacement
 * @property integer $created_at
 * @property integer $updated_at
 *
 * @property array $platforms
 * @property array $browsers
 * @property string $platformsReplacement
 * @property string $browsersReplacement
 *
 */
class TeaserRecord extends CommonRecord
{
	const TARGET_GENDER_ANY = 'any';
	const TARGET_GENDER_MALE = 'male';
	const TARGET_GENDER_FEMALE = 'female';

    public $image;

    /**
     * @return string
     */
    public static function tableName()
    {
        return '{{teasers_teasers}}';
    }

	public function behaviors()
	{
		return ArrayHelper::merge(parent::behaviors(), [
			'resolveSegmentation' => [
				'class' => ResolveSegmentationBehavior::className(),
				'relationConnectionsAttribute' => 'teaserConnections',
				'trackAttributes' => ['is_adult', 'is_download', 'is_deleted'],
				'resolveSegmentation' => function ($connection) {
					/** @var $campaign CampaignRecord */
					$campaign = $connection !== null ? $connection->campaign : null;
					if ($campaign !== null && (bool)$campaign->is_deleted === false && $campaign->resolveSegmentation()) {
						$campaign->update();
					}
				}
			]
		]);
	}

    /**
     * @return array
     */
    public function rules()
    {
        return array_merge(parent::rules(),[
            [['title', 'image', 'group'], 'required', 'on' => ['create'], 'message' => 'Не может быть пустим поле "{attribute}"'],
            [['title'], 'required', 'on' => ['update'], 'message' => 'Не может быть пустим поле "{attribute}"'],
            [['id', 'title', 'body', 'teaser_type'], 'safe', 'on' => 'search'],
            [['body'], 'default', 'value' => ''],
            [['title', 'group'], 'string', 'max' => 255],
            [['ctr_24h', 'ctr_month', 'ctr_alltime'], 'double'],
            [['image'], 'file', 'extensions' => ['png', 'jpg', 'gif']],
            [['image'], 'validateImage', 'on' => ['create']],
            [['is_deleted', 'is_adult', 'is_download'], 'in', 'range' => array_keys(\Yii::$app->getFormatter()->booleanFormat)],
            [['teaser_type'], 'in', 'range' => array_keys(Image::$mimes)],
			[['platforms', 'browsers'], 'safe'],
			['target_gender', 'in', 'range' => array_keys(self::targetsGender())]
        ]);
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        $main = [
            'id' => '№',
            'title' => 'Заголовок',
            'body' => 'Тело',
            'teaser_type' => 'Тип',
            'image' => 'Изображение',
            'group' => 'Группа',
            'is_deleted' => 'Удаленно',
            'is_adult' => '18+ (Для взрослых)',
            'is_download' => 'Загрузки',
			'browsers_json' => 'Браузеры',
			'platforms_json' => 'Платформы',
			'browsers' => 'Браузеры',
			'platforms' => 'Платформы',
			'browsersReplacement' => 'Браузеры',
			'platformsReplacement' => 'Платформы',
			'target_gender' => 'Таргетинг по полу',
			'targetGenderReplacement' => 'Таргетинг по полу'
        ];
        return array_merge(parent::attributeLabels(), $main);
    }

	public function beforeSave($insert)
	{
		$this->setPlatforms($this->platforms);
		$this->setBrowsers($this->browsers);

		return parent::beforeSave($insert);
	}

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getTeaserConnections()
    {
        return $this->hasMany(TeaserConnectionRecord::className(), ['teaser_id' => 'id']);
    }

    /**
     * @return Image
     */
    public function getImage()
    {
        return Image::fromTeaser($this);
    }

    /**
     * @return array
     */
    public static function getArrayImages()
    {
        $data = [];
        $list = static::find()->andWhere(['=', 'is_deleted', false])->all();
        /** @var $teaser $this */
        foreach ($list as $teaser) {
            $teaser->getImage()->setCdn(SelectorAbstract::factory(YII_ENV_PROD || YII_ENV_TEST ? 'current' : 'local'));
            $data[$teaser->id] = Html::img($teaser->getImage()->getUrl(), ['style' => 'max-width: 150px; max-height: 150px;']);
        }
        return $data;
    }

    /**
	 * @return null|string
	 */
	public function getBrowsersReplacement()
	{
		return $this->getBrowsers() === null
			? null
			: implode(', ', array_intersect_key(Browser::labels(), array_flip($this->getBrowsers())));
	}

	/**
	 * @return null|string
	 */
	public function getPlatformsReplacement()
	{
		return $this->getPlatforms() === null
			? null
			: implode(', ', array_intersect_key(Platform::labels(), array_flip($this->getPlatforms())));
	}

	/**
	 * @return array|null
	 */
	public function getPlatforms()
	{
		try {
			$platforms = json_decode($this->platforms_json, true);
			return is_array($platforms) && count($platforms) ? $platforms : null;
		} catch (Exception $ex) {
			return null;
		}
	}

	/**
	 * @param array $value
	 */
	public function setPlatforms($value)
	{
		$this->platforms_json = null;
		if (is_array($value) && count($value)) {
			$this->platforms_json = Json::encode($value);
		}
	}

	/**
	 * @return array|null
	 */
	public function getBrowsers()
	{
		try {
			$browsers = json_decode($this->browsers_json, true);
			return is_array($browsers) && count($browsers) ? $browsers : null;
		} catch (Exception $ex) {
			return null;
		}
	}

	/**
	 * @param array $value
	 */
	public function setBrowsers($value)
	{
		$this->browsers_json = null;
		if (is_array($value) && count($value)) {
			$this->browsers_json = Json::encode($value);
		}
	}

	/**
	 * @return null|string
	 */
	public function getTargetGenderReplacement()
	{
		return isset(self::targetsGender()[$this->target_gender]) ? self::targetsGender()[$this->target_gender] : null;
	}

	/**
     * @param array $params
     * @param int $pageSize
     * @return \yii\data\ActiveDataProvider
     */
    public function getDataProvider(array $params = [], $pageSize = 20)
    {
        $dataProvider = parent::getDataProvider($params, $pageSize);
        $dataProvider->setPagination(['pageSize' => 12]);
        $dataProvider->setSort([
            'attributes' => [
                'id' => [
                    'asc' => ['id' => SORT_ASC],
                    'desc' => ['id' => SORT_DESC],
                    'label' => '№'
                ],
                'title' => [
                    'asc' => ['title' => SORT_ASC],
                    'desc' => ['title' => SORT_DESC],
                    'label' => 'Заголовку'
                ],
                'ctr_24h' => [
                    'asc' => ['ctr_24h' => SORT_ASC],
                    'desc' => ['ctr_24h' => SORT_DESC],
                    'label' => $this->getAttributeLabel('ctr_24h')
                ],
                'ctr_month' => [
                    'asc' => ['ctr_month' => SORT_ASC],
                    'desc' => ['ctr_month' => SORT_DESC],
                    'label' => $this->getAttributeLabel('ctr_month')
                ],
                'ctr_alltime' => [
                    'asc' => ['ctr_alltime' => SORT_ASC],
                    'desc' => ['ctr_alltime' => SORT_DESC],
                    'label' => $this->getAttributeLabel('ctr_alltime')
                ]
            ],
            'defaultOrder' => ['id' => SORT_DESC]
        ]);
        $query = $dataProvider->query;
        if (!($this->load($params) && $this->validate())) {
            return $dataProvider;
        }
        $query->andFilterWhere([
            'id' => $this->id,
            'teaser_type' => $this->teaser_type,
            'group' => in_array($this->group, ['""', "''"], true) ? new Expression($this->group) : $this->group,
            'is_deleted' => $this->is_deleted,
            'is_adult' => $this->is_adult,
            'is_download' => $this->is_download,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ]);
        $query->andFilterWhere(['like', 'title', $this->title]);
        return $dataProvider;
    }

    /**
     * @param UploadedFile $attribute
     * @return bool
     */
    public function validateImage($attribute)
    {
        try {
            $image = new \Imagick($this->image->tempName);
            if (!$image->cropImage(1, 1, 0, 0)) {
                $this->addError('image', 'Проблема с изменением размера изображения');
            } elseif ($image->getImageBlob() === '') {
                $this->addError('image', 'Последовательность изображения в BLOB ровна 0, загрузите другое изображение');
            }
        } catch (\Exception $e) {
            $this->addError('image', 'Проблема с изображением, загрузите другое изображение');
        }
        return true;
    }

    /**
     * @param int $limit
     * @param bool $withAdult
     * @return mixed
     * @throws \Exception
     */
    public static function getRandomTeasers($limit = 30, $withAdult = false)
    {
        return self::getDb()->cache(function () use ($limit, $withAdult) {
            $selector = self::find()->where(['is_deleted' => false]);
            if (!$withAdult) {
                $selector->andWhere(['is_adult' => false]);
            }
            $selector->orderBy(new Expression('RAND()'))->limit($limit);
            return $selector->all();
        }, self::RANDOM_CASH_DURATION);
    }

	public static function targetsGender()
	{
		return [
			self::TARGET_GENDER_ANY => 'Не важно',
			self::TARGET_GENDER_MALE => 'Мужчины',
			self::TARGET_GENDER_FEMALE => 'Женщины'
		];
	}
}