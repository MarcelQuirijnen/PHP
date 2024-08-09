var app = angular.module('myApp', ['ngRoute']);
app.factory("services", ['$http', function($http, $scope) {
    var serviceBase = '/services/'
    var obj = {};

    obj.getRequests = function(){
        return $http.get(serviceBase + 'requests');
    }

    obj.getRequest = function(requestID){
        return $http.get(serviceBase + 'request?id=' + requestID);
    }

    obj.insertRequest = function (request) {
        return $http.post(serviceBase + 'insertRequest', request).then(function (results) {
            return results;
        });
    };

    obj.updateRequest = function (id, request) {
        return $http.post(serviceBase + 'updateRequest', {id:id, request:request}).then(function (status) {
            return status.data;
        });
    };

    obj.deleteRequest = function (id) {
        return $http.delete(serviceBase + 'deleteRequest?id=' + id).then(function (status) {
            return status.data;
        });
    };

    return obj;
}]);

app.controller('listCtrl', function ($scope, services) {
    services.getRequests().then(function(data) {
        $scope.requests = data.data;
    });
});

app.controller('editCtrl', function ($scope, $rootScope, $location, $routeParams, services, request) {
    var requestID = ($routeParams.requestID) ? parseInt($routeParams.requestID) : 0;
    $rootScope.title = (requestID > 0) ? 'Edit Feature Request' : 'Add Feature Request';
    $scope.buttonText = (requestID > 0) ? 'Update Feature Request' : 'Add New Feature Request';
    var original = request.data;
    original._id = requestID;
    $scope.request = angular.copy(original);
    $scope.request._id = requestID;

    $scope.isClean = function() {
        return angular.equals(original, $scope.request);
    }

    $scope.deleteRequest = function(request) {
        $location.path('/');
        if (confirm("Are you sure to delete Feature Request number: "+$scope.request._id)==true)
            services.deleteRequest(request.id);
    };

    $scope.saveRequest = function(request) {
        $location.path('/');
        if (requestID <= 0) {
            services.insertRequest(request);
        } else {
            services.updateRequest(requestID, request);
        }
    };
});

app.config(['$routeProvider',
    function($routeProvider) {
        $routeProvider.
            when('/', {
                title: 'Requests',
                templateUrl: '/partials/requests.html',
                controller: 'listCtrl'
            })
            .when('/edit-request/:requestID', {
                title: 'Edit Requests',
                templateUrl: '/partials/edit-request.html',
                controller: 'editCtrl',
                resolve: {
                    request: function(services, $route){
                        var requestID = $route.current.params.requestID;
                        return services.getRequest(requestID);
                    }
                }
            })
            .otherwise({
                redirectTo: '/'
            });
    }]);

app.run(['$location', '$rootScope', function($location, $rootScope) {
    $rootScope.$on('$routeChangeSuccess', function (event, current, previous) {
        $rootScope.title = current.$$route.title;
    });
}]);