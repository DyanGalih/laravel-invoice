# Frontend assets

`public/build/` is generated and is not tracked by Git. Laravel requires its
manifest and assets in production; Composer and Artisan do not build them.

With Node.js 24, run from the application root before serving a fresh checkout:

```sh
npm ci
npm run build
npm test
```

The build needs development dependencies, so do not use `npm ci --omit=dev` in
the build environment. Deploy the entire generated `public/build/` directory
with the matching application revision. Do not remove `resources/static/`;
it supplies files copied into the build.

CI builds and checks the frontend, then uploads `frontend-build`. A deployment
may download that artifact from the matching successful workflow run and extract
its contents into `public/build/`, instead of building on the server. Uploading
the artifact does not deploy it automatically.

For Docker Compose, `sh docker-compose/setup.sh` builds assets using a Node 24
container into the host checkout shared by PHP and nginx, before the existing
Laravel setup steps. It requires Docker and access to the npm registry; the
Compose services must already be running for the Laravel setup steps.
