<?php
$this->append("css");
?>

<style>
    .map {
        position: absolute;
        z-index: 1;
    }

    #myContainer {
        display: inline-block;
        width: 400px;
        height: 340px;
        margin: 0 auto;
        position: relative;
    }

    #camContainer{
        display: inline-block;
    }

    #mapCanvas {
        position: relative;
        z-index: 20;
    }
</style>

<?php
$this->end();
?>

<?php
$this->append('script');
?>
<script src="https://code.createjs.com/easeljs-0.8.2.min.js"></script>
<script src="https://static.robotwebtools.org/EventEmitter2/current/eventemitter2.min.js"></script>
<script src="https://static.robotwebtools.org/ros2djs/current/ros2d.min.js"></script>
<script
        src="https://code.jquery.com/jquery-3.3.1.min.js"
        integrity="sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8="
        crossorigin="anonymous"></script>
<script src="https://static.robotwebtools.org/roslibjs/current/roslib.min.js"></script>

<script>
    var ros = new ROSLIB.Ros();
    var mapWidth = 15;
    var mapHeight = 15;
    var mapResolution = 1;
    var mapOriginX = 0;
    var mapOriginY = 0;
    var canvas;
    var context;
    var xCoordinateOnRobot = 0;
    var yCoordinateOnRobot = 0;
    var angleOnRobot = 0;
    var xCoordinateOnMap = 0;
    var yCoordinateOnMap = 0;
    var positions = [];
    var diameter = 7;
    var sequence = 0;
    var goalExists = false;
    var goals = [];
    var clickedPositions = [];
    var clickTracker = 0;
    var isFinished = false;

    $(document).ready(function(){
        canvas = document.getElementById("mapCanvas");
        context = canvas.getContext('2d');
        canvas.addEventListener("mousedown", getCursorPosition, false);

        var viewer = new ROS2D.Viewer({
            divID: 'map',
            width: 400,
            height: 340
        });

        var gridClient = new ROS2D.OccupancyGridClient({
            ros: ros,
            rootObject: viewer.scene
        });

        gridClient.on('change', function () {
            viewer.scaleToDimensions(gridClient.currentGrid.width, gridClient.currentGrid.height);
            viewer.shift(mapOriginX, mapOriginY);
        });

        ros.connect('ws://<?= isset($ip) && $ip !== null ? $ip : $robot->ip_address ?>:<?= isset($port) && $port !== null ? $port : $robot->port ?>');

        ros.on('error', function (error) {
            document.getElementById('connecting').style.display = 'none';
            document.getElementById('connected').style.display = 'none';
            document.getElementById('closed').style.display = 'none';
            document.getElementById('error').style.display = 'inline';
            console.log(error);
        });

        ros.on('connection', function () {
            console.log('Connection made!');
            document.getElementById('connecting').style.display = 'none';
            document.getElementById('error').style.display = 'none';
            document.getElementById('closed').style.display = 'none';
            document.getElementById('connected').style.display = 'inline';
        });

        ros.on('close', function () {
            console.log('Connection closed.');
            document.getElementById('connecting').style.display = 'none';
            document.getElementById('connected').style.display = 'none';
            document.getElementById('closed').style.display = 'inline';
        });
    });

    var robotTopic = new ROSLIB.Topic({
        ros: ros,
        name: '<?= $robot->topic->name ?>',
        messageType: '<?= $robot->topic->mes_type->name ?>'
    });

    robotTopic.subscribe(function (message) {
        xCoordinateOnRobot = message.<?= $robot->topic->mes_type->x_par ?> - mapOriginX;
        yCoordinateOnRobot = message.<?= $robot->topic->mes_type->y_par ?> - mapOriginY;
        angleOnRobot = getRobotsAngleFromQuaternion(message.pose.pose.orientation);
        drawRobot();
    });

    function getRobotsAngleFromQuaternion(orientation){
        let w = orientation.w;
        let z = orientation.z;
        let sinus = 2 * w * z;
        let cosinus = 1 - (2 * z * z);
        return Math.atan2(sinus, cosinus);
    }

    function drawRobot(){
        if(canvas == undefined) canvas = document.getElementById("mapCanvas");
        if(context == undefined) context = canvas.getContext('2d');

        xCoordinateOnMap = calculateXCoordinate();
        yCoordinateOnMap = calculateYCoordinate();

        context.clearRect(0, 0, canvas.width, canvas.height); //Body of the robot
        context.beginPath();
        context.arc(xCoordinateOnMap , yCoordinateOnMap, diameter, 0, 2 * Math.PI, false);
        context.fillStyle = 'green';
        context.fill();
        context.lineWidth = 2;
        context.strokeStyle = '#003300';
        context.stroke();

        context.beginPath();  //Angle
        context.moveTo(xCoordinateOnMap, yCoordinateOnMap);
        context.lineTo(xCoordinateOnMap - diameter * Math.sin(angleOnRobot - Math.PI / 2), yCoordinateOnMap - diameter * Math.cos(angleOnRobot - Math.PI / 2));
        context.stroke();

        if(goalExists){
          drawGoals();
          drawPath();
        }

        displayCoordinatesAndAngle();
    }

    function calculateXCoordinate(){
        return (xCoordinateOnRobot / mapResolution) * (canvas.width / mapWidth) - (diameter / 2);
    }

    function calculateYCoordinate(){
        return (mapHeight - yCoordinateOnRobot / mapResolution) * (canvas.height / mapHeight) - (diameter / 2);
    }

    function displayCoordinatesAndAngle(){
        $("#x_cord").text((xCoordinateOnRobot + mapOriginX).toFixed(3));
        $("#y_cord").text((yCoordinateOnRobot + mapOriginY).toFixed(3));
        $("#theta").text((angleOnRobot).toFixed(3));
    }

    var pathTopic = new ROSLIB.Topic({
        ros: ros,
        name: "/move_base/NavfnROS/plan",
        messageType: 'nav_msgs/Path'
    });

    pathTopic.subscribe(function(message){
        var tempArray = [];
        var poses = message.poses;
        for(var i = 0; i < poses.length; i++){
            var pose = poses[i].pose.position;
            tempArray.push(pose);
        }

        positions = tempArray;
    });

    function drawPath(){
          for(var k = 0; k < positions.length-1; k++){
            context.beginPath();
            context.moveTo(((positions[k].x - mapOriginX) / mapResolution) * (canvas.width / mapWidth) - (diameter / 2), (mapHeight - (positions[k].y - mapOriginY) / mapResolution) * (canvas.height / mapHeight) - (diameter / 2));
            context.lineTo(((positions[k+1].x - mapOriginX) / mapResolution) * (canvas.width / mapWidth) - (diameter / 2), (mapHeight - (positions[k+1].y - mapOriginY) / mapResolution) * (canvas.height / mapHeight) - (diameter / 2));
            context.stroke();
          }
    }

    var cameraTopic = new ROSLIB.Topic({
        ros: ros,
        name: '/camera/rgb/image_raw/compressed',
        messageType: 'sensor_msgs/CompressedImage'
    });

    cameraTopic.subscribe(function (message) {
        var image = document.getElementById("cameraImg");
        image.src = "data:image/jpeg;base64," + message.data;
    });

    var mapTopic = new ROSLIB.Topic({
        ros: ros,
        name: "/map_metadata",
        messageType: 'nav_msgs/MapMetaData'
    });

    mapTopic.subscribe(function (message) {
        mapWidth = message.width;
        mapHeight = message.height;
        mapResolution = message.resolution;
        mapOriginX =  message.origin.position.x;
        mapOriginY =  message.origin.position.y;
        mapTopic.unsubscribe();
    });

    var goalResultTopic = new ROSLIB.Topic({
        ros: ros,
        name: "/move_base/result",
        messageType: 'move_base_msgs/MoveBaseActionResult'
    });
    goalResultTopic.subscribe(function(message){
        var status = message.status.status;
        console.log(status);

        if(status == 3){
          isFinished = true;
          clickedPositions.shift();
           if(clickedPositions.length > 0){
              sendPositionToRobot(clickedPositions[0]);
           }

           if(clickedPositions.length == 0){
             goalExists = false;
             document.getElementById("goals").innerHTML = "";
           }
        }
        if(status == 4){

        }
    });

    function drawGoals(){
        for(var index = 0; index < clickedPositions.length; index++){
          context.beginPath();
          context.arc(clickedPositions[index].x -3, clickedPositions[index].y - 3, 3 , 0, 2 * Math.PI, false);
          context.stroke();
        }

        var text = "<ol>";
        for (var i = 0; i < clickedPositions.length; i++) {
            text += "<li>" + "x: " + clickedPositions[i].x + ", y: " + clickedPositions[i].y + "</li>";
        }
        text += "</ol>";

        document.getElementById("goals").innerHTML = text;
    }

    var goalTopic = new ROSLIB.Topic({
      ros : ros,
      name : '/move_base_simple/goal',
      messageType : 'geometry_msgs/PoseStamped'
    });

    goalTopic.subscribe(function (message) {
        var goal = {};

        goal.x = ((message.pose.position.x - mapOriginX) / mapResolution) * (canvas.width / mapWidth);
        goal.y = (mapHeight - (message.pose.position.y - mapOriginY) / mapResolution) * (canvas.height / mapHeight);

        goals.push(goal);
    });

    function getCursorPosition(event) {
        var rectangle = canvas.getBoundingClientRect();
        var clickedPosition = {};
        clickedPosition.x = event.clientX - rectangle.left;
        clickedPosition.y = event.clientY - rectangle.top;

        clickedPositions.push(clickedPosition);
        goalExists = true;
        if(isFinished == true){
          sendPositionToRobot(clickedPosition);
          isFinished = false;
        }

        if(clickTracker == 0){
            sendPositionToRobot(clickedPosition);
            clickTracker++;
        }
    }

    function sendPositionToRobot(positionToSend){
        var poseStamped = new ROSLIB.Message({
          header : {
            seq : sequence,
            stamp : (new Date).getTime(),
            frame_id : "/odom"
          },
          pose : {
            position : new ROSLIB.Vector3({
              x: (positionToSend.x * mapWidth * mapResolution / canvas.width) + mapOriginX ,
              y: (mapHeight - (positionToSend.y * mapHeight / canvas.height)) * mapResolution + mapOriginY,
              z: 0
            }),
            orientation : new ROSLIB.Quaternion()
          }
        });

        sequence++;
        goalTopic.publish(poseStamped);
    }

</script>

<?php $this->end(); ?>

<div class="container">
    <h2 style="margin-top:10px;"><?= $robot->name ?></h2>
    <hr align="left" >
    <div class="row" style="margin: 30px auto;">
        <div class="col-md-5">
             <div class="card bg-light d-flex">
                <div class="card-body align-items-center d-flex justify-content-center">
                    <div id="map" class="map"></div>
                    <canvas id="mapCanvas" width="400" height="340"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-7">
             <div class="card bg-light d-flex">
                <div class="card-body align-items-center d-flex justify-content-center">
                    <img class="image-responsive center-block" id="cameraImg" width="565" height="340"/>
                </div>
            </div>
        </div>
    </div>
    <div class="row" style="margin: 30px auto;">
        <div class="col-md-5">
             <div class="card bg-light">
                <div class="card-body">
                  <div>
                      <span style="font-weight:bold;"><?php echo __('X coordinate:'); ?></span>
                      <span id="x_cord"></span>
                      <br>
                      <span style="font-weight:bold;"><?php echo __('Y coordinate:'); ?></span>
                      <span id="y_cord"></span>
                      <br>
                      <span style="font-weight:bold;"><?php echo __('Angle:'); ?></span>
                      <span id="theta"></span>
                      <br>
                  </div>

                  <div id="statusIndicator">
                      <p id="connecting">
                          <span style="font-weight:bold;"><?php echo __('Status:'); ?> </span><span style="color:blue;"><?php echo __('Connecting to rosbridge...'); ?></span>
                      </p>
                      <p id="connected" style="display:none">
                          <span style="font-weight:bold;"><?php echo __('Status:'); ?> </span><span style="color:#00D600;"><?php echo __('Connected'); ?></span>
                      </p>
                      <p id="error" style="display:none">
                        <span style="font-weight:bold;"><?php echo __('Statuts:'); ?> </span><span style="color:#FF0000;"><?php echo __('Error in the backend!'); ?></span>
                      </p>
                      <p id="closed" style="display:none">
                          <span style="font-weight:bold;"><?php echo __('Status:'); ?> </span><span style="color:#FF0000;"><?php echo __('Connection closed.'); ?></span>
                      </p>
                  </div>
                </div>
            </div>
        </div>
        <div class="col-md-7">
             <div class="card bg-light">
                <div class="card-body">
                  <div id="goalsContainer">
                    <div id="goals"></div>
                  </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div id="camContainer">
    <img id="cameraImg"/>
</div>
