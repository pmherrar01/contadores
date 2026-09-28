// Cliente mínimo de la API gRPC de ChirpStack v4 con promesas.
'use strict';
const grpc = require('@grpc/grpc-js');

const internalService = require('@chirpstack/chirpstack-api/api/internal_grpc_pb');
const tenantService = require('@chirpstack/chirpstack-api/api/tenant_grpc_pb');
const userService = require('@chirpstack/chirpstack-api/api/user_grpc_pb');
const deviceProfileService = require('@chirpstack/chirpstack-api/api/device_profile_grpc_pb');
const applicationService = require('@chirpstack/chirpstack-api/api/application_grpc_pb');
const gatewayService = require('@chirpstack/chirpstack-api/api/gateway_grpc_pb');
const deviceService = require('@chirpstack/chirpstack-api/api/device_grpc_pb');

const internal = require('@chirpstack/chirpstack-api/api/internal_pb');

class ChirpStack {
  constructor(servidor) {
    const cred = grpc.credentials.createInsecure();
    this.metadata = new grpc.Metadata();
    this.internal = new internalService.InternalServiceClient(servidor, cred);
    this.tenant = new tenantService.TenantServiceClient(servidor, cred);
    this.user = new userService.UserServiceClient(servidor, cred);
    this.deviceProfile = new deviceProfileService.DeviceProfileServiceClient(servidor, cred);
    this.application = new applicationService.ApplicationServiceClient(servidor, cred);
    this.gateway = new gatewayService.GatewayServiceClient(servidor, cred);
    this.device = new deviceService.DeviceServiceClient(servidor, cred);
  }

  // Llama a un método gRPC y devuelve una promesa.
  call(cliente, metodo, req) {
    return new Promise((resolve, reject) => {
      cliente[metodo](req, this.metadata, {deadline: Date.now() + 15000}, (err, res) => (err ? reject(err) : resolve(res)));
    });
  }

  async login(usuario, password) {
    const req = new internal.LoginRequest();
    req.setEmail(usuario);
    req.setPassword(password);
    const res = await this.call(this.internal, 'login', req);
    this.metadata = new grpc.Metadata();
    this.metadata.set('authorization', 'Bearer ' + res.getJwt());
  }

  async perfil() {
    return this.call(this.internal, 'profile', new (require('google-protobuf/google/protobuf/empty_pb').Empty)());
  }
}

function esNoEncontrado(err) {
  return err && err.code === grpc.status.NOT_FOUND;
}

module.exports = {ChirpStack, esNoEncontrado};
