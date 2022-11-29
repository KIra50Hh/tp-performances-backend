<?php

namespace App\Services\Hotel;

use App\Common\FilterException;
use App\Common\SingletonTrait;
use App\Entities\HotelEntity;
use App\Entities\RoomEntity;
use App\Services\Room\RoomService;
use App\Common\Timers;
use App\Common\PDOSingleton;
use Exception;
use PDO;

/**
 * Une classe utilitaire pour récupérer les données des magasins stockés en base de données
 */
class UnoptimizedHotelService extends AbstractHotelService {
  
  use SingletonTrait;
  
  
  protected function __construct () {
    parent::__construct( new RoomService() );
  }
  
  
  /**
   * Récupère une nouvelle instance de connexion à la base de donnée
   *
   * @return PDO
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getDB () : PDO {
    $timer = Timers::getInstance();
    $timerId = $timer->startTimer('LoadDB');
    $pdo = PDOSingleton::get();
    $timer->endTimer('LoadDB', $timerId);
    return $pdo;
    

    
  }
  
  
  /**
   * Récupère une méta-donnée de l'instance donnée
   *
   * @param int    $userId
   * @param string $key
   *
   * @return string|null
   */
  protected function getMeta ( int $userId, string $key ) : ?string {
    $db = $this->getDB();
    $stmt = $db->prepare( 'SELECT meta_value FROM wp_usermeta WHERE user_id = :user_id AND meta_key = :meta_key' );
    $stmt->execute([
      'user_id' => $userId,
      'meta_key' => $key,
    ]);
    
    $result = $stmt->fetch( PDO::FETCH_ASSOC );
    
    return $result['meta_value'] ?? null;
  }
  
  
  /**
   * Récupère toutes les meta données de l'instance donnée
   *
   * @param HotelEntity $hotel
   *
   * @return array
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getMetas ( HotelEntity $hotel ) : array {
    $metaDatas = [
      'address' => [
        'address_1' => $this->getMeta( $hotel->getId(), 'address_1' ),
        'address_2' => $this->getMeta( $hotel->getId(), 'address_2' ),
        'address_city' => $this->getMeta( $hotel->getId(), 'address_city' ),
        'address_zip' => $this->getMeta( $hotel->getId(), 'address_zip' ),
        'address_country' => $this->getMeta( $hotel->getId(), 'address_country' ),
      ],
      'geo_lat' =>  $this->getMeta( $hotel->getId(), 'geo_lat' ),
      'geo_lng' =>  $this->getMeta( $hotel->getId(), 'geo_lng' ),
      'coverImage' =>  $this->getMeta( $hotel->getId(), 'coverImage' ),
      'phone' =>  $this->getMeta( $hotel->getId(), 'phone' ),
    ];
    
    return $metaDatas;
  }
  
  
  /**
   * Récupère les données liées aux évaluations des hotels (nombre d'avis et moyenne des avis)
   *
   * @param HotelEntity $hotel
   *
   * @return array{rating: int, count: int}
   * @noinspection PhpUnnecessaryLocalVariableInspection
   */
  protected function getReviews ( HotelEntity $hotel ) : array {
    // Récupère tous les avis d'un hotel
    $stmt = $this->getDB()->prepare( "SELECT COUNT(meta_value) as cpt,Round(AVG(meta_value)) as moy FROM wp_posts, wp_postmeta WHERE wp_posts.post_author = :hotelId AND wp_posts.ID = wp_postmeta.post_id AND meta_key = 'rating' AND post_type = 'review'" );
    $stmt->execute( [ 'hotelId' => $hotel->getId() ] );
    $reviews = $stmt->fetchAll( PDO::FETCH_ASSOC );
    

    $output = [
      'rating' => (int) $reviews[0]['moy'] ?? 0,
      'count' => (int)$reviews[0]['cpt'] ?? 0,
    ];
    
    return $output;
  }
  
  
  /**
   * Récupère les données liées à la chambre la moins chère des hotels
   *
   * @param HotelEntity $hotel
   * @param array{
   *   search: string | null,
   *   lat: string | null,
   *   lng: string | null,
   *   price: array{min:float | null, max: float | null},
   *   surface: array{min:int | null, max: int | null},
   *   rooms: int | null,
   *   bathRooms: int | null,
   *   types: string[]
   * }                  $args Une liste de paramètres pour filtrer les résultats
   *
   * @throws FilterException
   * @return RoomEntity
   */
  protected function getCheapestRoom ( HotelEntity $hotel, array $args = [] ) : RoomEntity {
    // On charge toutes les chambres de l'hôtel
    $query = "SELECT * FROM wp_posts JOIN wp_postmeta ON wp_posts.ID = wp_postmeta.post_id WHERE post_author = :hotelID AND post_type = 'room'";

    $whereClauses = [];

    if (isset($args['surface']['min']))
        $whereClauses[] = 'surface >= :surfaceMin';

    if (isset($args['surface']['max']))
        $whereClauses[] = 'surface <= :surfaceMax';

    if (isset($args['price']['min']))
        $whereClauses[] = 'price >= :priceMin';

    if (isset($args['price']['max']))
        $whereClauses[] = 'price <= :priceMax';

    if (isset($args['rooms']))
        $whereClauses[] = 'rooms => :rooms';

    if (isset($args['bathRooms']))
        $whereClauses[] = 'bathRooms => :bathRooms';

    if (count($whereClauses) > 0)
        $query .= ' AND ' . implode(' AND ', $whereClauses);

    $stmt = $this->getDB()->prepare( $query );

    if (isset($args['surface']['min']))
        $stmt->bindValue(':surfaceMin', $args['surface']['min'], PDO::PARAM_INT);

    if (isset($args['surface']['max']))
        $stmt->bindValue(':surfaceMax', $args['surface']['max'], PDO::PARAM_INT);

    if (isset($args['price']['min']))
        $stmt->bindValue(':priceMin', $args['price']['min'], PDO::PARAM_INT);

    if (isset($args['price']['max']))
        $stmt->bindValue(':priceMax', $args['price']['max'], PDO::PARAM_INT);

    if (isset($args['rooms']))
        $stmt->bindValue(':rooms', $args['rooms'], PDO::PARAM_INT);

    if (isset($args['bathRooms']))
        $stmt->bindValue(':bathRooms', $args['bathRooms'], PDO::PARAM_INT);

    $stmt->bindValue(':hotelID', $hotel->getId(), PDO::PARAM_INT);
    $stmt->execute();
    $filteredRooms = $stmt->fetchAll( PDO::FETCH_ASSOC );
    
    // Si aucune chambre ne correspond aux critères, alors on déclenche une exception pour retirer l'hôtel des résultats finaux de la méthode list().
    if ( count( $filteredRooms ) < 1 )
      throw new FilterException( "Aucune chambre ne correspond aux critères" );

    $result = [];
    for ( $i = 0; $i < count( $filteredRooms ); $i++ ) {
        $result[] = $this->getRoomService()->get( $filteredRooms[$i]['ID'] );
    }
    
    // Trouve le prix le plus bas dans les résultats de recherche
    $cheapestRoom = null;
    foreach ( $result as $room ) :
      if ( ! isset( $cheapestRoom ) ) {
        $cheapestRoom = $room;
        continue;
      }

      if ( intval( $room->getPrice() ) < intval( $cheapestRoom->getPrice() ) )
        $cheapestRoom = $room;
    endforeach;
    
    return $cheapestRoom;
  
    // Si aucune chambre ne correspond aux critères, alors on déclenche une exception pour retirer l'hôtel des résultats finaux de la méthode list().
    if ( count( $filteredRooms ) < 1 )
      throw new FilterException( "Aucune chambre ne correspond aux critères" );
    
    
    // Trouve le prix le plus bas dans les résultats de recherche
    $cheapestRoom = null;
    foreach ( $filteredRooms as $room ) :
      if ( ! isset( $cheapestRoom ) ) {
        $cheapestRoom = $room;
        continue;
      }
      
      if ( intval( $room->getPrice() ) < intval( $cheapestRoom->getPrice() ) )
        $cheapestRoom = $room;
    endforeach;
    
    return $cheapestRoom;
  }
  
  
  /**
   * Calcule la distance entre deux coordonnées GPS
   *
   * @param $latitudeFrom
   * @param $longitudeFrom
   * @param $latitudeTo
   * @param $longitudeTo
   *
   * @return float|int
   */
  protected function computeDistance ( $latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo ) : float|int {
    return ( 111.111 * rad2deg( acos( min( 1.0, cos( deg2rad( $latitudeTo ) )
          * cos( deg2rad( $latitudeFrom ) )
          * cos( deg2rad( $longitudeTo - $longitudeFrom ) )
          + sin( deg2rad( $latitudeTo ) )
          * sin( deg2rad( $latitudeFrom ) ) ) ) ) );
  }
  
  
  /**
   * Construit une ShopEntity depuis un tableau associatif de données
   *
   * @throws Exception
   */
  protected function convertEntityFromArray ( array $data, array $args ) : HotelEntity {
    $hotel = ( new HotelEntity() )
      ->setId( $data['ID'] )
      ->setName( $data['display_name'] );
    
    // Charge les données meta de l'hôtel
    
    $timer = Timers::getInstance();
    $timerId = $timer->startTimer('loadHotelData');
    $metasData = $this->getMetas( $hotel );
    $timer->endTimer('loadHotelData', $timerId);  

    $hotel->setAddress( $metasData['address'] );
    $hotel->setGeoLat( $metasData['geo_lat'] );
    $hotel->setGeoLng( $metasData['geo_lng'] );
    $hotel->setImageUrl( $metasData['coverImage'] );
    $hotel->setPhone( $metasData['phone'] );
    
    // Définit la note moyenne et le nombre d'avis de l'hôtel
    $timer = Timers::getInstance();
    $timerId = $timer->startTimer('loadReview');
    $reviewsData = $this->getReviews( $hotel );
    $timer->endTimer('loadReview', $timerId);
    $hotel->setRating( $reviewsData['rating'] );
    $hotel->setRatingCount( $reviewsData['count'] );
    
    // Charge la chambre la moins chère de l'hôtel
    $timer = Timers::getInstance();
    $timerId = $timer->startTimer('loadHotel');
    $cheapestRoom = $this->getCheapestRoom( $hotel, $args );
    $timer->endTimer('loadHotel', $timerId);
    $hotel->setCheapestRoom($cheapestRoom);

    header('Server-Timing: ' . Timers::getInstance()->getTimers() );
    
    // Verification de la distance
    if ( isset( $args['lat'] ) && isset( $args['lng'] ) && isset( $args['distance'] ) ) {
      $hotel->setDistance( $this->computeDistance(
        floatval( $args['lat'] ),
        floatval( $args['lng'] ),
        floatval( $hotel->getGeoLat() ),
        floatval( $hotel->getGeoLng() )
      ) );
      
      if ( $hotel->getDistance() > $args['distance'] )
        throw new FilterException( "L'hôtel est en dehors du rayon de recherche" );
    }
    
    return $hotel;
  }
  
  
  /**
   * Retourne une liste de boutiques qui peuvent être filtrées en fonction des paramètres donnés à $args
   *
   * @param array{
   *   search: string | null,
   *   lat: string | null,
   *   lng: string | null,
   *   price: array{min:float | null, max: float | null},
   *   surface: array{min:int | null, max: int | null},
   *   bedrooms: int | null,
   *   bathrooms: int | null,
   *   types: string[]
   * } $args Une liste de paramètres pour filtrer les résultats
   *
   * @throws Exception
   * @return HotelEntity[] La liste des boutiques qui correspondent aux paramètres donnés à args
   */
  public function list ( array $args = [] ) : array {
    $db = $this->getDB();
    $stmt = $db->prepare( "SELECT * FROM wp_users" );
    $stmt->execute();
    
    $results = [];
    foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
      try {
        $results[] = $this->convertEntityFromArray( $row, $args );
      } catch ( FilterException ) {
        // Des FilterException peuvent être déclenchées pour exclure certains hotels des résultats
      }
    }
    
    
    return $results;
  }
}