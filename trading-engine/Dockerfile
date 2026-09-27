FROM node:24.21.0-bookworm-slim AS build

ENV PNPM_HOME=/pnpm
ENV PATH=$PNPM_HOME:$PATH

WORKDIR /app

RUN corepack enable && corepack prepare pnpm@12.6.0 --activate

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile

COPY tsconfig.json tsconfig.build.json eslint.config.js vitest.config.ts ./
COPY apps ./apps
COPY src ./src

RUN pnpm build && pnpm prune --prod

FROM node:24.21.0-bookworm-slim AS runtime

ENV NODE_ENV=production
WORKDIR /app

RUN groupadd --system --gid 10001 engine \
    && useradd --system --uid 10001 --gid engine --home-dir /app engine

COPY --from=build --chown=engine:engine /app/package.json /app/pnpm-lock.yaml ./
COPY --from=build --chown=engine:engine /app/node_modules ./node_modules
COPY --from=build --chown=engine:engine /app/dist ./dist

USER engine
EXPOSE 3100

CMD ["node", "dist/apps/api/src/main.js"]
