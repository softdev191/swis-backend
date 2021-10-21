<html>
<body>
<div style="text-align:center;width:100%;background-color:#00aced;">
    <img src="{{url('img/swis_logo.png')}}" height="40" alt="Swis logo" style="text-align:center;padding:20px;"/>
</div>
<div style="width:400px;margin: auto">
    <div style="padding-top:10px;">
        <h3>Dear Admin</h3>
        <p>I am "{{$name}}".</p>
        <p>{{$message_part}}</p>
        <p><a href="https://admin.swis.app/profile?id={{$id}}">User Profile</a></p>
    </div>
</div>
</body>
</html>