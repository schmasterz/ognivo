# Official Node.js Alpine image
FROM node:26-alpine

# bash for convenience, libc6-compat for Next.js native binaries (SWC) on Alpine (musl)
RUN apk add --no-cache bash libc6-compat

# Set the working directory inside the container
WORKDIR /app

# Copy package.json and package-lock.json first (caches the dependency layer)
COPY package*.json ./

# Install npm dependencies
RUN npm install

# Copy the rest of the frontend source code
COPY . .

# Expose port 3000 for the Next.js development server
EXPOSE 3000

# Start the Next.js development server with hot reloading
CMD ["npm", "run", "dev"]